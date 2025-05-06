<?php

namespace CirrusSearch\Event;

use CirrusSearch\CirrusIntegrationTestCase;
use CirrusSearch\HashSearchConfig;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Config\HashConfig;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Deferred\LinksUpdate\LinksUpdate;
use MediaWiki\Extension\EventBus\EventBus;
use MediaWiki\Extension\EventBus\EventBusFactory;
use MediaWiki\Extension\EventBus\StreamNameMapper;
use MediaWiki\Page\ExistingPageRecord;
use MediaWiki\Page\PageLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\Title\TitleFormatter;
use MediaWiki\User\UserIdentity;
use Wikimedia\UUID\GlobalIdGenerator;

class EventBusBridgeTest extends CirrusIntegrationTestCase {
	/**
	 * @dataProvider provideTestFactory
	 * @covers \CirrusSearch\Event\EventBusBridge::factory
	 */
	public function testFactory( $enabled ) {
		$configFactory = new ConfigFactory();
		$titleFormatter = $this->createNoOpMock( TitleFormatter::class );
		$pageLookup = $this->createNoOpMock( PageLookup::class );
		$globalIdGenerator = $this->createNoOpMock( GlobalIdGenerator::class );
		$configFactory->register( 'CirrusSearch',
			static function () use ( $enabled ) {
				return new HashSearchConfig( [ 'CirrusSearchUseEventBusBridge' => $enabled ] );
			} );

		if ( class_exists( EventBusFactory::class ) ) {
			$eventBusFactory = $this->createNoOpMock( EventBusFactory::class );
			$streamNameMapper = new StreamNameMapper( [] );
			$service = EventBusBridge::factory( $configFactory, new HashConfig(), $globalIdGenerator,
				$titleFormatter, $pageLookup, $eventBusFactory, $streamNameMapper );
			if ( $enabled ) {
				$this->assertInstanceOf( EventBusBridge::class, $service );
			} else {
				$this->assertNotInstanceOf( EventBusBridge::class, $service );
			}
		}
		// Test that EventBusFactory is optional
		$service = EventBusBridge::factory( $configFactory, new HashConfig(),
			$globalIdGenerator, $titleFormatter, $pageLookup );
		$this->assertNotInstanceOf( EventBusBridge::class, $service );
	}

	public static function provideTestFactory(): array {
		return [
			'enabled' => [ true ],
			'disabled' => [ false ]
		];
	}

	/**
	 * @dataProvider provideTestLinksUpdate
	 * @covers \CirrusSearch\Event\EventBusBridge::onLinksUpdateComplete
	 */
	public function testLinksUpdate( bool $redirect, bool $withEdit, bool $nullEdit, bool $rerender ) {
		if ( !class_exists( EventBusFactory::class ) ) {
			$this->markTestSkipped( "EventBus not available" );
		}
		$cleanup = DeferredUpdates::preventOpportunisticUpdates();
		$pageId = 123;
		$streamName = 'mystream';
		$event = [
			'meta' => [
				'stream' => $streamName
			]
		];
		$page = $this->createMock( ExistingPageRecord::class );
		$page->method( 'isRedirect' )->willReturn( $redirect );
		$page->method( 'getId' )->willReturn( $pageId );

		$pageLookup = $this->createMock( PageLookup::class );
		$pageLookup->method( 'getPageById' )->with( $pageId )->willReturn( $page );

		$linksUpdate = $this->createMock( LinksUpdate::class );
		$linksUpdate->method( 'getPageId' )->willReturn( $pageId );

		$pageRerenderSerializer = $this->createMock( PageRerenderSerializer::class );

		$eventBusFactory = $this->createMock( EventBusFactory::class );
		$eventBus = $this->createMock( EventBus::class );

		$eventBusFactory->method( 'getInstanceForStream' )->with( $streamName )->willReturn( $eventBus );

		$eventBus->expects( $this->exactly( $rerender ? 1 : 0 ) )
			->method( 'send' )->with( [ $event ] );

		$pageRerenderSerializer->expects( $this->exactly( $rerender ? 1 : 0 ) )
			->method( 'eventDataForPage' )
			->with( $page, PageRerenderSerializer::LINKS_UPDATE_REASON, $this->anything() )
			->willReturn( $event );

		$bridge = new EventBusBridge( $eventBusFactory, $pageLookup, $pageRerenderSerializer );
		$bridge->onLinksUpdateComplete( $linksUpdate, null );
		if ( $withEdit ) {
			$editResult = new EditResult( false, false, null, null, null, false, $nullEdit, [] );
			$bridge->onPageSaveComplete( $page,
				$this->createNoOpMock( UserIdentity::class ), '', 0,
				$this->createNoOpMock( RevisionRecord::class ), $editResult );
		}
		DeferredUpdates::doUpdates();
	}

	public static function provideTestLinksUpdate(): array {
		return [
			'no rerender on redirect, non null edit' => [ true, true, false, false ],
			'no rerender on redirect, null edit' => [ true, true, true, false ],
			'no rerender page, non null edit' => [ false, true, false, false ],
			'rerender page, null edit' => [ false, true, true, true ],
			'rerender page, no edit' => [ false, false, false, true ],
		];
	}

}
