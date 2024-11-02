<?php

namespace CirrusSearch;

use MediaWiki\Extension\EventBus\EventBus;
use MediaWiki\Extension\EventBus\EventBusFactory;
use MediaWiki\Page\PageRecord;
use MediaWiki\Page\WikiPageFactory;
use MediaWikiUnitTestCase;
use PHPUnit\Framework\Constraint\Callback;
use PHPUnit\Framework\MockObject\MockObject;
use WikiPage;

/**
 * @coversDefaultClass \CirrusSearch\EventBusWeightedTagsUpdater
 */
class EventBusWeightedTagsUpdaterTest extends MediaWikiUnitTestCase {

	private EventBusWeightedTagsUpdater $updater;
	/**
	 * @var EventBusFactory&object&MockObject
	 */
	private EventBusFactory $mockEventBusFactory;
	/**
	 * @var EventBusWeightedTagSerializer&MockObject
	 */
	private EventBusWeightedTagSerializer $mockEventSerializer;
	/**
	 * @var WikiPageFactory&object&MockObject
	 */
	private WikiPageFactory $mockWikiPageFactory;

	/**
	 * @var EventBus&object&MockObject
	 */
	private EventBus $mockEventBus;

	protected function setUp(): void {
		$this->mockEventBusFactory = $this->createMock( EventBusFactory::class );
		$this->mockEventSerializer = $this->createMock( EventBusWeightedTagSerializer::class );
		$this->mockWikiPageFactory = $this->createMock( WikiPageFactory::class );

		$this->mockEventSerializer->expects( $this->any() )->method( 'getStream' )
			->willReturn( EventBusWeightedTagSerializer::STREAM );

		$this->mockEventBus = $this->createMock( EventBus::class );
		$this->mockEventBusFactory->expects( $this->any() )->method( 'getInstanceForStream' )
			->with( EventBusWeightedTagSerializer::STREAM )
			->willReturn( $this->mockEventBus );

		$this->updater = new EventBusWeightedTagsUpdater(
			$this->mockEventBusFactory,
			$this->mockEventSerializer,
			$this->mockWikiPageFactory
		);
	}

	public static function getUpdateWeightedTagsData(): array {
		return [
			[
				'prefix-0',
				[ 'tag-a' => 5 ],
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
	 * @dataProvider getUpdateWeightedTagsData
	 */
	public function testUpdateWeightedTags(
		string $tagPrefix,
		?array $tagWeights = null,
		?string $trigger = null
	): void {
		$pageRecord = $this->createMock( PageRecord::class );
		$wikiPage = $this->createMock( WikiPage::class );
		$this->mockWikiPageFactory->expects( $this->any() )->method( 'newFromTitle' )->with( $pageRecord )->willReturn(
			$wikiPage
		);

		$event = [ 'irrelevant' ];
		$set = [];
		$this->mockEventSerializer->expects( self::once() )->method( 'toSetEvent' )->with(
				$wikiPage,
				$this->captureArg( $set ),
				$trigger === 'revision' ? true : null,
				null
			)->willReturn( $event );
		$this->mockEventBus->expects( self::once() )->method( 'send' )->with( $event );

		$this->updater->updateWeightedTags( $pageRecord, $tagPrefix, $tagWeights, $trigger );

		$this->assertArrayHasKey( $tagPrefix, $set );
		foreach ( ( $tagWeights === null ? [ 'exists' ] : array_keys( $tagWeights ) ) as $tagName ) {
			$this->assertArrayHasKey( $tagName, $set[$tagPrefix] );
		}
	}

	public static function getResetWeightedTagsData(): array {
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
	 * @dataProvider getResetWeightedTagsData
	 */
	public function testResetWeightedTags( array $tagPrefixes, ?string $trigger = null ) {
		$pageRecord = $this->createMock( PageRecord::class );
		$wikiPage = $this->createMock( WikiPage::class );
		$this->mockWikiPageFactory->expects( $this->any() )->method( 'newFromTitle' )->with( $pageRecord )->willReturn(
			$wikiPage
		);

		$event = [ 'irrelevant' ];
		$this->mockEventSerializer->expects( self::once() )->method( 'toClearEvent' )->with(
				$wikiPage,
				$tagPrefixes,
				$trigger === 'revision' ? true : null
			)->willReturn( $event );
		$this->mockEventBus->expects( self::once() )->method( 'send' )->with( $event );

		$this->updater->resetWeightedTags( $pageRecord, $tagPrefixes, $trigger );
	}

	private function captureArg( &$captor ): Callback {
		return $this->callback( static function ( $arg ) use ( &$captor ) {
			$captor = $arg;

			return true;
		} );
	}
}
