<?php

namespace extensions\CirrusSearch\tests\phpunit\integration\Event;

use CirrusSearch\CirrusIntegrationTestCase;
use CirrusSearch\EventBusWeightedTagSerializer;
use CirrusSearch\EventBusWeightedTagsUpdater;
use CirrusSearch\WeightedTagsException;
use Exception;
use JsonSchema\Validator;
use MediaWiki\Config\Config;
use MediaWiki\Extension\EventBus\EventBus;
use MediaWiki\Extension\EventBus\EventBusFactory;
use MediaWiki\Extension\EventBus\Serializers\EventSerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\PageEntitySerializer;
use MediaWiki\Http\Telemetry;
use MediaWiki\Json\FormatJson;
use MediaWiki\Page\PageRecord;
use MediaWiki\Page\WikiPage;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Settings\Source\Format\YamlFormat;
use MediaWiki\Tests\MockWikiMapTrait;
use MediaWiki\Title\TitleFormatter;
use PHPUnit\Framework\Constraint\TraversableContainsEqual;
use PHPUnit\Framework\MockObject\MockObject;
use Wikimedia\UUID\GlobalIdGenerator;

/**
 * @coversDefaultClass \CirrusSearch\EventBusWeightedTagsUpdater
 */
class EventBusWeightedTagsUpdaterTest extends CirrusIntegrationTestCase {
	use MockWikiMapTrait;

	private EventBusWeightedTagsUpdater $updater;
	/**
	 * @var EventBusFactory&object&MockObject
	 */
	private EventBusFactory $mockEventBusFactory;
	/**
	 * @var EventBusWeightedTagSerializer
	 */
	private EventBusWeightedTagSerializer $eventSerializer;
	/**
	 * @var WikiPageFactory&object&MockObject
	 */
	private WikiPageFactory $mockWikiPageFactory;

	/**
	 * @var EventBus&object&MockObject
	 */
	private EventBus $mockEventBus;

	/**
	 * @var Validator
	 */
	private Validator $validator;

	/**
	 * @var array
	 */
	private array $validatorSchema;

	protected function setUp(): void {
		parent::setUp();

		$this->markTestSkippedIfExtensionNotLoaded( 'EventBus' );

		$this->mockWikiMap();
		$this->mockEventBusFactory = $this->createMock( EventBusFactory::class );
		$this->mockWikiPageFactory = $this->createMock( WikiPageFactory::class );

		$this->mockEventBus = $this->createMock( EventBus::class );
		$this->mockEventBusFactory->expects( $this->any() )->method( 'getInstanceForStream' )
			->with( EventBusWeightedTagSerializer::STREAM )
			->willReturn( $this->mockEventBus );

		$this->eventSerializer = $this->createEventSerializerMock();
		$this->updater = new EventBusWeightedTagsUpdater(
			$this->mockEventBusFactory,
			$this->eventSerializer,
			$this->mockWikiPageFactory
		);

		$this->validator = new Validator;
		$yamlFormat = new YamlFormat;
		$this->validatorSchema = $yamlFormat->decode(
			file_get_contents( __DIR__ . '/page_change_weighted_tags-1.0.0.yaml' )
		);
	}

	/**
	 * @return EventBusWeightedTagSerializer&MockObject
	 */
	protected function createEventSerializerMock(): EventBusWeightedTagSerializer {
		$mainConfig = $this->createMock( Config::class );
		$mainConfig->expects( $this->any() )->method( 'get' )->willReturnArgument( 0 );
		$idGenerator = $this->createMock( GlobalIdGenerator::class );
		$telemetry = $this->createMock( Telemetry::class );
		$titleFormatter = $this->createMock( TitleFormatter::class );
		$titleFormatter->expects( $this->any() )->method( 'getPrefixedDBkey' )->willReturn( 'test' );
		return new EventBusWeightedTagSerializer(
			new EventSerializer( $mainConfig, $idGenerator, $telemetry ), new PageEntitySerializer( $mainConfig, $titleFormatter )
		);
	}

	public static function provideUpdateWeightedTagsData(): array {
		return [
			[
				'prefix-0',
				[ 'tag-a' => 1 ],
				'revision'
			],
			[
				'prefix-0',
				[ 'tag-a' => 5 ]
			],
			[
				'prefix-0',
				[ 'tag-a' => null ]
			],
			[ 'prefix-0' ],
		];
	}

	/**
	 * @covers ::updateWeightedTags
	 * @param string $tagPrefix
	 * @param array|null $tagWeights
	 * @param string|null $trigger
	 *
	 * @dataProvider provideUpdateWeightedTagsData
	 */
	public function testUpdateWeightedTags(
		string $tagPrefix,
		?array $tagWeights = null,
		?string $trigger = null
	): void {
		$pageRecord = $this->createPageRecordMock();

		$eventCaptor = $this->captureEvent();
		$this->updater->updateWeightedTags( $pageRecord, $tagPrefix, $tagWeights, $trigger );
		$event = $eventCaptor();

		$this->assertArrayHasKey( 'weighted_tags', $event );
		$this->assertArrayHasKey( 'set', $event['weighted_tags'] );
		$set = $event['weighted_tags']['set'];

		if ( $trigger === 'revision' ) {
			$this->assertArrayHasKey( 'rev_based', $event );
			$this->assertTrue( $event['rev_based'] );
		} else {
			$this->assertArrayNotHasKey( 'rev_based', $event );
		}

		foreach ( ( $tagWeights === null ? [ 'exists' ] : array_keys( $tagWeights ) ) as $tagName ) {
			$mappedWeightedTag = [ 'tag' => $tagName ];
			if ( $tagName !== 'exists' && isset( $tagWeights[ $tagName ] ) ) {
				$mappedWeightedTag[ 'score' ] = $tagWeights[ $tagName ] / 1000;
			}
			self::assertThat( $set[ $tagPrefix ] ?? null, new TraversableContainsEqual( $mappedWeightedTag ) );
		}

		$this->assertCompliesToSchema( $event );
	}

	/**
	 * @covers ::updateWeightedTags
	 */
	public function testUpdateFailsWithString(): void {
		$pageRecord = $this->createPageRecordMock();

		$this->mockEventBus->expects( $this->once() )->method( 'send' )->willReturn( 'string-response' );
		$this->expectException( WeightedTagsException::class );
		$this->expectExceptionMessageMatches( '/string-response/' );

		$this->updater->updateWeightedTags( $pageRecord, 'any', [ 'a' => 5 ], 'revision' );
	}

	/**
	 * @covers ::updateWeightedTags
	 */
	public function testUpdateFailsWithArray(): void {
		$pageRecord = $this->createPageRecordMock();

		$this->mockEventBus->expects( $this->once() )->method( 'send' )->willReturn( [ 'error-a', 'error-b' ] );
		$this->expectException( WeightedTagsException::class );
		$this->expectExceptionMessageMatches( '/error-a/' );
		$this->expectExceptionMessageMatches( '/error-b/' );

		$this->updater->updateWeightedTags( $pageRecord, 'any', [ 'a' => 5 ], 'revision' );
	}

	/**
	 * @covers ::updateWeightedTags
	 */
	public function testUpdateFailsWithException(): void {
		$pageRecord = $this->createPageRecordMock();

		$this->mockEventBus->expects( $this->once() )->method( 'send' )->willThrowException( new Exception( 'cause' ) );
		$this->expectException( Exception::class );
		$this->expectExceptionMessageMatches( '/cause/' );

		$this->updater->updateWeightedTags( $pageRecord, 'any', [ 'a' => 5 ], 'revision' );
	}

	/**
	 * @covers ::resetWeightedTags
	 */
	public function testResetFailsWithString(): void {
		$pageRecord = $this->createPageRecordMock();

		$this->mockEventBus->expects( $this->once() )->method( 'send' )->willReturn( 'string-response' );
		$this->expectException( WeightedTagsException::class );
		$this->expectExceptionMessageMatches( '/string-response/' );

		$this->updater->resetWeightedTags( $pageRecord, [ 'any' ] );
	}

	/**
	 * @covers ::resetWeightedTags
	 */
	public function testResetFailsWithArray(): void {
		$pageRecord = $this->createPageRecordMock();

		$this->mockEventBus->expects( $this->once() )->method( 'send' )->willReturn( [ 'error-a', 'error-b' ] );
		$this->expectException( WeightedTagsException::class );
		$this->expectExceptionMessageMatches( '/error-a/' );
		$this->expectExceptionMessageMatches( '/error-b/' );

		$this->updater->resetWeightedTags( $pageRecord, [ 'any' ] );
	}

	/**
	 * @covers ::resetWeightedTags
	 */
	public function testResetFailsWithException(): void {
		$pageRecord = $this->createPageRecordMock();

		$this->mockEventBus->expects( $this->once() )->method( 'send' )->willThrowException( new Exception( 'cause' ) );
		$this->expectException( Exception::class );
		$this->expectExceptionMessageMatches( '/cause/' );

		$this->updater->resetWeightedTags( $pageRecord, [ 'any' ] );
	}

	public static function provideResetWeightedTagsData(): array {
		return [
			[
				[ 'prefix-0' ],
				'revision'
			],
			[
				[
					'prefix-0',
					'prefix-1'
				]
			],
		];
	}

	/**
	 * @covers ::resetWeightedTags
	 * @param string[] $tagPrefixes
	 * @param string|null $trigger
	 *
	 * @dataProvider provideResetWeightedTagsData
	 */
	public function testResetWeightedTags( array $tagPrefixes, ?string $trigger = null ) {
		$pageRecord = $this->createPageRecordMock();

		$eventCaptor = $this->captureEvent();
		$this->updater->resetWeightedTags( $pageRecord, $tagPrefixes, $trigger );
		$event = $eventCaptor();

		$this->assertArrayHasKey( 'weighted_tags', $event );
		$this->assertArrayHasKey( 'clear', $event['weighted_tags'] );
		if ( $trigger === 'revision' ) {
			$this->assertArrayHasKey( 'rev_based', $event );
			$this->assertTrue( $event['rev_based'] );
		} else {
			$this->assertArrayNotHasKey( 'rev_based', $event );
		}
		$clear = $event['weighted_tags']['clear'];

		$this->assertArrayContains( $clear, $tagPrefixes );

		$this->assertCompliesToSchema( $event );
	}

	/**
	 * @return PageRecord&MockObject
	 */
	private function createPageRecordMock(): PageRecord {
		$pageRecord = $this->createMock( PageRecord::class );
		$wikiPage = $this->createMock( WikiPage::class );
		$wikiPage->expects( $this->any() )->method( 'isRedirect' )->willReturn( false );
		$this->mockWikiPageFactory->expects( $this->any() )->method( 'newFromTitle' )->with( $pageRecord )->willReturn(
			$wikiPage
		);

		return $pageRecord;
	}

	private function assertCompliesToSchema( array $event ): void {
		$this->assertFalse( isset( $event['meta']['id'] ) );
		$event['meta']['id'] = 'test';

		$eventObj = json_decode( FormatJson::encode( $event ) );
		$this->validator->validate( $eventObj, $this->validatorSchema );
		$this->assertEquals( $event['$schema'], $this->validatorSchema['$id'] );
		$this->assertTrue( $this->validator->isValid(), var_export( $this->validator->getErrors(), true ) );

		// remove required property, just to make sure validation actually fails
		unset( $event['page'] );
		$eventObj = json_decode( FormatJson::encode( $event ) );
		$this->validator->validate( $eventObj, $this->validatorSchema );
		$this->assertFalse( $this->validator->isValid() );
	}

	private function captureEvent(): callable {
		$args = [];
		$this->mockEventBus->expects( self::once() )->method( 'send' )->with( $this->captureArgs( $args ) )->willReturn( true );
		return static function () use ( &$args ) {
			return $args[0];
		};
	}
}
