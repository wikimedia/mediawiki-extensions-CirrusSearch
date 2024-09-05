<?php

namespace CirrusSearch;

use MediaWiki\Extension\EventBus\EventBus;
use MediaWiki\Extension\EventBus\EventBusFactory;
use MediaWiki\Page\PageRecord;
use MediaWiki\Page\WikiPageFactory;
use MediaWikiUnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;

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

	/**
	 * @covers ::updateWeightedTags
	 */
	public function testUpdateWeightedTags() {
		$page = $this->createMock( PageRecord::class );
		$event = [ 'dt' => 'test' ];
		$this->mockEventSerializer->expects( self::once() )
			->method( 'toSetEvent' )
			->willReturn( $event );

		$this->mockEventBus->expects( self::once() )->method( 'send' )->with( $event );
		$this->updater->updateWeightedTags( $page, 'prefix' );
	}

	/**
	 * @covers ::resetWeightedTags
	 */
	public function testResetWeightedTags() {
		$page = $this->createMock( PageRecord::class );
		$event = [ 'dt' => 'test' ];
		$this->mockEventSerializer->expects( self::once() )
			->method( 'toClearEvent' )
		->willReturn( $event );
		$this->mockEventBus->expects( self::once() )->method( 'send' )->with( $event );
		$this->updater->resetWeightedTags( $page, [ 'prefix-0', 'prefix-1' ] );
	}
}
