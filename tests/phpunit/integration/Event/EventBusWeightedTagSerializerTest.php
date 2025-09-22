<?php

namespace CirrusSearch\Event;

use CirrusSearch\EventBusWeightedTagSerializer;
use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\EventBus\Serializers\EventSerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\PageEntitySerializer;
use MediaWiki\Http\Telemetry;
use MediaWiki\Page\WikiPage;
use MediaWiki\Title\Title;
use MediaWiki\WikiMap\WikiMap;
use MediaWikiIntegrationTestCase;
use Wikimedia\UUID\GlobalIdGenerator;

/**
 * @coversDefaultClass \CirrusSearch\EventBusWeightedTagSerializer
 * @group Database
 * @group EventBus
 */
class EventBusWeightedTagSerializerTest extends MediaWikiIntegrationTestCase {
	private const MOCK_CANONICAL_SERVER = 'http://my_wiki.org';
	private const MOCK_ARTICLE_PATH = '/wiki/$1';
	private const MOCK_SERVER_NAME = 'my_wiki';

	private const MOCK_UUID = 'b14a2ee4-f5df-40f3-b995-ce6c954e29e3';
	private const MOCK_STREAM_NAME = 'test.mediawiki.page_change_weighted_tags';

	/**
	 * @var EventSerializer
	 */
	private EventSerializer $eventSerializer;
	/**
	 * @var PageEntitySerializer
	 */
	private PageEntitySerializer $pageEntitySerializer;

	/**
	 * @var EventBusWeightedTagSerializer
	 */
	private EventBusWeightedTagSerializer $weightedTagSerializer;

	public function setUp(): void {
		parent::setUp();

		$this->markTestSkippedIfExtensionNotLoaded( 'EventBus' );

		$config = new HashConfig( [
			'ServerName' => self::MOCK_SERVER_NAME,
			'CanonicalServer' => self::MOCK_CANONICAL_SERVER,
			'ArticlePath' => self::MOCK_ARTICLE_PATH
		] );
		$globalIdGenerator = $this->createMock( GlobalIdGenerator::class );
		$globalIdGenerator->method( 'newUUIDv4' )->willReturn( self::MOCK_UUID );

		$telemetry = $this->createMock( Telemetry::class );
		$telemetry->method( 'getRequestId' )->willReturn( 'requestid' );

		$this->eventSerializer = new EventSerializer(
			$config,
			$globalIdGenerator,
			$telemetry
		);

		$this->pageEntitySerializer = new PageEntitySerializer(
			$config,
			$this->getServiceContainer()->getTitleFormatter()
		);

		$this->weightedTagSerializer = new EventBusWeightedTagSerializer(
			$this->eventSerializer,
			$this->pageEntitySerializer, self::MOCK_STREAM_NAME
		);
	}

	/**
	 * DRY helper function to help dynamically generate some common
	 * event attributes we are expecting to have on a page change event
	 * for the $wikiPage.
	 *
	 * @param WikiPage $wikiPage
	 * @param string|null $eventTimestamp
	 * @param array|null $eventAttrs
	 * @return array
	 */
	private function createExpectedPageChangeEvent(
		WikiPage $wikiPage,
		?string $eventTimestamp = null,
		?array $eventAttrs = null
	): array {
		$eventTimestamp = $eventTimestamp ?? $wikiPage->getRevisionRecord()->getTimestamp();
		$wikiId = $wikiPage->getWikiId() ?: WikiMap::getCurrentWikiId();

		return array_merge_recursive(
			$this->eventSerializer->createEvent(
				EventBusWeightedTagSerializer::SCHEMA,
				self::MOCK_STREAM_NAME,
				$this->pageEntitySerializer->canonicalPageURL( $wikiPage ),
				[
					'wiki_id' => $wikiId,
					'dt' => EventSerializer::timestampToDt( $eventTimestamp ),
					'page' => $this->pageEntitySerializer->toArray( $wikiPage ),
				],
				$wikiId
			),
			$eventAttrs
		);
	}

	public static function provideSetEventData(): array {
		return [
			[ [ 'weighted_tags' => [ 'set' => [ 'prefix-0' => [ 'tag-a' => 1 ] ] ] ] ],
			[ [ 'weighted_tags' => [ 'set' => [ 'prefix-0' => [ 'tag-a' => 1 ] ] ], 'rev_based' => true ] ],
			[ [ 'weighted_tags' => [ 'set' => [ 'prefix-0' => [ 'tag-a' => 1 ] ] ], 'rev_based' => false ] ],
		];
	}

	/**
	 * @covers ::toSetEvent
	 * @covers ::toArray
	 * @param array $eventAttrs
	 * @dataProvider provideSetEventData
	 */
	public function testSetEvent( array $eventAttrs ) {
		$wikiPage0 = $this->getExistingTestPage( Title::newFromText( 'MyPageToEdit', $this->getDefaultWikitextNS() ) );

		$dt = EventSerializer::timestampToDt();

		$expected = $this->createExpectedPageChangeEvent(
			$wikiPage0,
			$dt,
			$eventAttrs,
		);

		$actual = $this->weightedTagSerializer->toSetEvent(
			$wikiPage0,
			$eventAttrs['weighted_tags']['set'],
			$eventAttrs['rev_based'] ?? null,
			$dt
		);

		$this->assertEquals( $expected, $actual );
	}

	public static function provideClearEventData(): array {
		return [
			[ [ 'weighted_tags' => [ 'clear' => [ 'prefix-0' ] ] ] ],
			[ [ 'weighted_tags' => [ 'clear' => [ 'prefix-0', 'prefix-1' ] ], 'rev_based' => true ] ],
			[ [ 'weighted_tags' => [ 'clear' => [ 'prefix-0', 'prefix-1' ] ], 'rev_based' => false ] ],
		];
	}

	/**
	 * @covers ::toSetEvent
	 * @covers ::toArray
	 * @param array $eventAttrs
	 * @dataProvider provideClearEventData
	 */
	public function testClearEvent( array $eventAttrs ) {
		$wikiPage0 = $this->getExistingTestPage( Title::newFromText( 'MyPageToEdit', $this->getDefaultWikitextNS() ) );

		$dt = EventSerializer::timestampToDt();

		$expected = $this->createExpectedPageChangeEvent(
			$wikiPage0,
			$dt,
			$eventAttrs,
		);

		$actual = $this->weightedTagSerializer->toClearEvent(
			$wikiPage0,
			$eventAttrs['weighted_tags']['clear'],
			$eventAttrs['rev_based'] ?? null,
			$dt
		);

		$this->assertEquals( $expected, $actual );
	}
}
