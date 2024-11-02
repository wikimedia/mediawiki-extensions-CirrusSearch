<?php

namespace CirrusSearch\Event;

use CirrusSearch\Connection;
use CirrusSearch\SearchConfig;
use MediaWiki\Config\Config;
use MediaWiki\Extension\EventBus\StreamNameMapper;
use MediaWiki\MainConfigNames;
use MediaWiki\Page\PageRecord;
use MediaWiki\Page\PageReference;
use MediaWiki\Title\TitleFormatter;
use Wikimedia\Assert\Assert;
use Wikimedia\UUID\GlobalIdGenerator;

class PageRerenderSerializer {
	public const STREAM = 'mediawiki.cirrussearch.page_rerender.v1';
	public const SCHEMA = '/mediawiki/cirrussearch/page_rerender/1.0.0';
	public const LINKS_UPDATE_REASON = 'links_update';
	private TitleFormatter $titleFormatter;
	private SearchConfig $searchConfig;
	private GlobalIdGenerator $globalIdGenerator;
	private Config $mainConfig;
	private Connection $cirrusConnection;
	private StreamNameMapper $streamNameMapper;

	/**
	 * @param Config $mainConfig
	 * @param TitleFormatter $titleFormatter
	 * @param SearchConfig $searchConfig
	 * @param GlobalIdGenerator $globalIdGenerator
	 * @param StreamNameMapper $streamNameMapper
	 */
	public function __construct(
		Config $mainConfig,
		TitleFormatter $titleFormatter,
		SearchConfig $searchConfig,
		GlobalIdGenerator $globalIdGenerator,
		StreamNameMapper $streamNameMapper
	) {
		$this->titleFormatter = $titleFormatter;
		$this->searchConfig = $searchConfig;
		$this->globalIdGenerator = $globalIdGenerator;
		$this->mainConfig = $mainConfig;
		$this->streamNameMapper = $streamNameMapper;
	}

	public function eventDataForPage( PageRecord $page, string $reason, string $requestId, ?string $dt = null ): array {
		Assert::parameter( $page->isRedirect() === false, '$page', 'Redirects are not supported' );
		$connection = $this->getCirrusConnection();
		$attrs = [
			'$schema' => self::SCHEMA,
			'meta' => [
				'stream' => $this->streamNameMapper->resolve( self::STREAM ),
				'uid' => $this->globalIdGenerator->newUUIDv4(),
				'request_id' => $requestId,
				'domain' => $this->getDomain(),
				'uri' => $this->getCanonicalPageURL( $page )
			],
			'reason' => $reason,
			'dt' => $dt ?? wfTimestamp( TS_ISO_8601 ),
			'wiki_id' => $this->searchConfig->getWikiId(),
			'cirrussearch_index_name' => $connection->getIndexName( $this->searchConfig->get( SearchConfig::INDEX_BASE_NAME ),
						$connection->getIndexSuffixForNamespace( $page->getNamespace() ) ),
			'cirrussearch_cluster_group' => $this->searchConfig->getClusterAssignment()->getCrossClusterName() ?? "default",
			'page_id' => $page->getId(),
			'page_title' => $this->titleFormatter->getPrefixedDBkey( $page ),
			'namespace_id' => $page->getNamespace(),
			'is_redirect' => $page->isRedirect()
		];
		return $attrs;
	}

	private function getCirrusConnection(): Connection {
		$this->cirrusConnection ??= new Connection( $this->searchConfig );
		return $this->cirrusConnection;
	}

	private function getDomain(): string {
		return $this->mainConfig->get( MainConfigNames::ServerName );
	}

	private function getCanonicalPageURL( PageReference $wikiPage ): string {
		$titleURL = wfUrlencode( $this->titleFormatter->getPrefixedDBkey( $wikiPage ) );
		// The ArticlePath contains '$1' string where the article title should appear.
		return $this->mainConfig->get( MainConfigNames::CanonicalServer ) .
			   str_replace( '$1', $titleURL, $this->mainConfig->get( MainConfigNames::ArticlePath ) );
	}
}
