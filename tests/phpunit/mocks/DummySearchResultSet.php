<?php

namespace CirrusSearch\Test;

use CirrusSearch\Search\BaseCirrusSearchResultSet;
use CirrusSearch\Search\Result;

class DummySearchResultSet extends BaseCirrusSearchResultSet {
	/**
	 * @var \Elastica\ResultSet
	 */
	private $resultSet;

	/**
	 * @param int $totalHits
	 * @param bool $withSyntax
	 */
	private function __construct( $totalHits ) {
		$results = [];
		foreach ( range( 1, min( $totalHits, 20 ) ) as $i ) {
			$results[] = new \Elastica\Result( [] );
		}
		$this->resultSet = new \Elastica\ResultSet(
			new \Elastica\Response( [ "hits" => [ "total" => $totalHits ] ] ),
			new \Elastica\Query(),
			$results
		);
	}

	/**
	 * @param int $totalHits
	 * @param int[] $interwikiTotals total hits for secondary results interwiki results.
	 * @return DummySearchResultSet
	 */
	public static function fakeTotalHits( $totalHits, array $interwikiTotals = [] ) {
		$results = new self( $totalHits );
		foreach ( $interwikiTotals as $pref => $iwTotal ) {
			$results->addInterwikiResults( self::fakeTotalHits( $iwTotal ), self::SECONDARY_RESULTS, (string)$pref );
		}
		return $results;
	}

	/**
	 * @param int $totalHits
	 * @param string|null $suggestionQuery
	 * @param null $suggestionSnippet
	 * @return DummySearchResultSet
	 */
	public static function fakeTotalHitsWithSuggestion( $totalHits, $suggestionQuery = null, $suggestionSnippet = null ) {
		$res = self::fakeTotalHits( $totalHits );
		$res->setSuggestionQuery( $suggestionQuery, $suggestionSnippet );

		return $res;
	}

	/**
	 * @param \Elastica\Result $result Result from search engine
	 * @return Result|null Elasticsearch result transformed into mediawiki
	 *  search result object.
	 */
	protected function transformOneResult( \Elastica\Result $result ) {
		return new Result( $this, $result );
	}

	/**
	 * @return \Elastica\ResultSet|null
	 */
	public function getElasticaResultSet() {
		return $this->resultSet;
	}

	/**
	 * Did the search contain search syntax?  If so, Special:Search won't offer
	 * the user a link to a create a page named by the search string because the
	 * name would contain the search syntax.
	 * @return bool
	 */
	public function searchContainedSyntax() {
		return false;
	}
}
