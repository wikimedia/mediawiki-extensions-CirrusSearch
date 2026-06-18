<?php

namespace CirrusSearch;

use Elastica\Exception\ResponseException;
use Elastica\Request;
use MediaWiki\Title\TitleFactory;

/**
 * Runs a single-document _explain for one page against a query.
 *
 * Unlike a query-level explain, this can help explain why a page does not
 * match a query.
 *
 * Returns an array describing whether the page is in the index, whether it
 * matched, and the raw Lucene explanation.
 *
 * Index is derived from the page's own namespace independent of the
 * request's search namespaces.
 *
 * @license GPL-2.0-or-later
 */
class PageExplainer {

	private Connection $connection;
	private SearchConfig $config;
	private TitleFactory $titleFactory;
	private string $indexBaseName;

	public function __construct(
		Connection $connection,
		SearchConfig $config,
		TitleFactory $titleFactory,
		string $indexBaseName
	) {
		$this->connection = $connection;
		$this->config = $config;
		$this->titleFactory = $titleFactory;
		$this->indexBaseName = $indexBaseName;
	}

	/**
	 * Resolve the page id, issue the `_explain` and shape the response into the
	 * dump blob.
	 *
	 * A page id that does not resolve to a local title (deleted / unknown) and a
	 * document absent from the index both collapse to found:false; a debug probe
	 * has no need to tell a deleted page from an unindexed one.
	 *
	 * @param int $pageId local mediawiki page id to explain
	 * @param array $queryClause the query clause to explain against
	 * @return array{found:bool,matched?:bool,explanation?:array,query:array,index:?string,docId:?string}
	 */
	public function explain( int $pageId, array $queryClause ): array {
		$routing = $this->resolve( $pageId );
		if ( $routing === null ) {
			return [
				'found' => false,
				'query' => $queryClause,
				'index' => null,
				'docId' => null,
			];
		}
		return $this->runExplain( $routing['index'], $routing['docId'], $queryClause );
	}

	/**
	 * Resolve a local page id to the canonical index name and document id
	 *
	 * @param int $pageId local mediawiki page id
	 * @return array{index:string,docId:string}|null the index name and document
	 *  id to `_explain`, or null when the page id does not resolve to a local
	 *  title (deleted / unknown id).
	 */
	public function resolve( int $pageId ): ?array {
		$title = $this->titleFactory->newFromID( $pageId );
		if ( $title === null ) {
			return null;
		}
		$suffix = $this->connection->getIndexSuffixForNamespace( $title->getNamespace() );
		return [
			'index' => $this->connection->getIndexName( $this->indexBaseName, $suffix ),
			'docId' => $this->config->makeId( $pageId ),
		];
	}

	/**
	 * Issue the `_explain` and shape the response into the dump blob.
	 *
	 * @param string $indexName index resolved from the page's namespace
	 * @param string $docId opensearch document id for the page
	 * @param array $queryClause the query clause to explain
	 * @return array the explain blob
	 */
	private function runExplain( string $indexName, string $docId, array $queryClause ): array {
		$notFound = [
			'found' => false,
			'query' => $queryClause,
			'index' => $indexName,
			'docId' => $docId,
		];
		try {
			$response = $this->connection->getClient()->request(
				// rawurlencode the doc id: with CirrusSearchPrefixIds the id is
				// "wikiid|pageid", and the bare "|" is not a legal URL path char.
				"$indexName/_explain/" . rawurlencode( $docId ),
				Request::GET,
				[ 'query' => $queryClause ]
			);
		} catch ( ResponseException $e ) {
			// A missing document yields a 404; treat it as "not in the search
			// index" rather than a query failure. Anything else is a real error.
			if ( $e->getResponse()->getStatus() === 404 ) {
				return $notFound;
			}
			throw $e;
		}

		$data = $response->getData();
		if ( !isset( $data['explanation'] ) ) {
			// Some backends answer a missing doc with a 404 carrying matched:false
			// and no explanation rather than throwing; same "not indexed" reading.
			return $notFound;
		}
		return [
			'found' => true,
			'matched' => $data['matched'] ?? false,
			'explanation' => $data['explanation'],
			'query' => $queryClause,
			'index' => $indexName,
			'docId' => $docId,
		];
	}
}
