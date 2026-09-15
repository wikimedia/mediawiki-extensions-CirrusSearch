<?php

namespace CirrusSearch\Dispatch\Voter;

use CirrusSearch\CirrusDebugOptions;
use CirrusSearch\Connection;
use CirrusSearch\Search\SearchQuery;
use CirrusSearch\Search\TitleResultsType;
use CirrusSearch\SearchConfig;
use CirrusSearch\Searcher;

/**
 * Asks the search backend whether a query names a page that exists.
 *
 * Runs the same near-match query that Special:Search runs to send "catapult" straight to
 * the Catapult page, so a veto built on this lookup agrees with the near-match behavior
 * the reader already sees. The query matches titles and redirects, not article text.
 *
 * This is one extra request to the backend, and it must complete before the main query
 * can be built, so it adds a full round trip to the latency of every query that reaches
 * it. The answer is kept for the life of the object, because a single request can route
 * the same query more than once.
 *
 * @license GPL-2.0-or-later
 */
class SearcherTitleMatchLookup implements TitleMatchLookup {
	private SearchConfig $config;

	/** @var array<string, bool|null> Answers already obtained, keyed by lookup. */
	private array $answers = [];

	public function __construct( SearchConfig $config ) {
		$this->config = $config;
	}

	public function matchesExistingTitle( SearchQuery $query ): ?bool {
		$term = trim( $query->getParsedQuery()->getQueryWithoutNsHeader() );
		$namespaces = $query->getNamespaces();
		if ( $term === '' ) {
			return false;
		}
		$key = implode( '|', $namespaces ) . "\n" . $term;
		if ( !array_key_exists( $key, $this->answers ) ) {
			$this->answers[$key] = $this->lookup( $term, $namespaces );
		}
		return $this->answers[$key];
	}

	/**
	 * @param string $term
	 * @param int[] $namespaces empty to search every namespace
	 * @return bool|null
	 */
	private function lookup( string $term, array $namespaces ): ?bool {
		$searcher = new Searcher(
			Connection::getPool( $this->config ),
			0,
			1,
			$this->config,
			$namespaces ?: null,
			null,
			false,
			// The routing decision is not a debug request, whatever the query that
			// prompted it asked for: dumping this query would hide the main one.
			CirrusDebugOptions::defaultOptions()
		);
		$searcher->setResultsType( new TitleResultsType() );
		$status = $searcher->nearMatchTitleSearch( $term );
		if ( !$status->isOK() ) {
			return null;
		}
		return $status->getValue() !== [];
	}
}
