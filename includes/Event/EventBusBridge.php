<?php

namespace CirrusSearch\Event;

use CirrusSearch\PageChangeTracker;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\EventBus\EventBusFactory;
use MediaWiki\Extension\EventBus\StreamNameMapper;
use MediaWiki\Page\PageLookup;
use MediaWiki\Request\WebRequest;
use MediaWiki\Title\TitleFormatter;
use Wikimedia\UUID\GlobalIdGenerator;

/**
 * Hook handler responsible for bridging internal MW events to dedicated event streams.
 */
class EventBusBridge extends PageChangeTracker implements EventBridge {
	private EventBusFactory $eventBusFactory;
	private PageLookup $pageLookup;
	private PageRerenderSerializer $pageRerenderSerializer;

	/**
	 * @param EventBusFactory $eventBusFactory
	 * @param PageLookup $pageLookup
	 * @param PageRerenderSerializer $pageRerenderSerializer
	 */
	public function __construct(
		EventBusFactory $eventBusFactory,
		PageLookup $pageLookup,
		PageRerenderSerializer $pageRerenderSerializer,
		int $maxStateSize = 512
	) {
		parent::__construct( $maxStateSize );
		$this->eventBusFactory = $eventBusFactory;
		$this->pageLookup = $pageLookup;
		$this->pageRerenderSerializer = $pageRerenderSerializer;
	}

	/**
	 * @param ConfigFactory $configFactory
	 * @param Config $mainConfig
	 * @param GlobalIdGenerator $globalIdGenerator
	 * @param TitleFormatter $titleFormatter
	 * @param PageLookup $pageLookup
	 * @param EventBusFactory|null $eventBusFactory
	 * @return EventBridge
	 */
	public static function factory(
		ConfigFactory $configFactory,
		Config $mainConfig,
		GlobalIdGenerator $globalIdGenerator,
		TitleFormatter $titleFormatter,
		PageLookup $pageLookup,
		?EventBusFactory $eventBusFactory = null,
		?StreamNameMapper $streamNameMapper = null
	): EventBridge {
		$config = $configFactory->makeConfig( "CirrusSearch" );
		'@phan-var \CirrusSearch\SearchConfig $config';
		if ( $eventBusFactory !== null && $config->get( 'CirrusSearchUseEventBusBridge' ) ) {
			if ( $streamNameMapper === null ) {
				throw new \RuntimeException( 'EventBusFactory provided without StreamNameMapper' );
			}
			$pageRerenderSerializer = new PageRerenderSerializer( $mainConfig, $titleFormatter,
				$config, $globalIdGenerator, $streamNameMapper );
			return new self( $eventBusFactory, $pageLookup, $pageRerenderSerializer );
		}
		return new class() implements EventBridge {
			/**
			 * @inheritDoc
			 */
			public function onLinksUpdateComplete( $linksUpdate, $ticket ) {
			}
		};
	}

	/**
	 * @inheritDoc
	 */
	public function onLinksUpdateComplete( $linksUpdate, $ticket ) {
		DeferredUpdates::addCallableUpdate( function () use ( $linksUpdate ) {
			if ( $this->isPageChange( $linksUpdate->getPageId() ) ) {
				// Page changes are handled via the page-change stream
				return;
			}
			$page = $this->pageLookup->getPageById( $linksUpdate->getPageId() );
			if ( $page === null ) {
				// the page no longer exists
				return;
			}
			if ( $page->isRedirect() ) {
				// We are not really interested in redirects at this point
				// since we would ultimately refresh the target of this redirect we assume
				// that a LinksUpdate for the redirect does not imply that the target page has
				// to re-render as well.
				return;
			}
			$event = $this->pageRerenderSerializer->eventDataForPage( $page,
				PageRerenderSerializer::LINKS_UPDATE_REASON, WebRequest::getRequestId() );
			// Fire and forget, we do not check the return value, problems should be already logged by
			// EventBus
			$this->eventBusFactory
				->getInstanceForStream( $event['meta']['stream'] )
				->send( [ $event ] );
		} );
	}
}
