<?php

namespace CirrusSearch\Test;

use CirrusSearch\Search\ResultSet;
use SearchResultSet;

class DummyResultSet extends ResultSet {
	/**
	 * @var string|null
	 */
	private $overriddenSuggestion = null;

	/**
	 * @var string|null
	 */
	private $overriddenSuggestionSnippet;

	/**
	 * @var bool
	 */
	private $useOverriddenSuggestion = false;

	/**
	 * DummyResultSet constructor.
	 * @param int $totalHits
	 * @param bool $withSyntax
	 */
	public function __construct( $totalHits, $withSyntax = false ) {
		parent::__construct( $withSyntax,
			new \Elastica\ResultSet( new \Elastica\Response( [ "hits" => [ "total" => $totalHits ] ] ),
				new \Elastica\Query(),
				[] ) );
		$this->results = array_fill( 0, min( $totalHits, 20 ), null );
	}

	/**
	 * @param int $totalHits
	 * @param int[] $interwikiTotals total hits for secondary results interwiki results.
	 * @return DummyResultSet
	 */
	public static function fakeTotalHits( $totalHits, array $interwikiTotals = [] ) {
		$results = new self( $totalHits );
		foreach ( $interwikiTotals as $pref => $iwTotal ) {
			$results->addInterwikiResults( self::fakeTotalHits( $iwTotal ), SearchResultSet::SECONDARY_RESULTS, (string)$pref );
		}
		return $results;
	}

	/**
	 * @param int $totalHits
	 * @param string|null $suggestionQuery
	 * @param null $suggestionSnippet
	 * @return DummyResultSet
	 */
	public static function fakeTotalHitsWithSuggestion( $totalHits, $suggestionQuery = null, $suggestionSnippet = null ) {
		$res = self::fakeTotalHits( $totalHits );
		$res->overriddenSuggestion = $suggestionQuery;
		$res->overriddenSuggestionSnippet = $suggestionSnippet;
		$res->useOverriddenSuggestion = true;

		return $res;
	}

	/**
	 * @return null|string
	 */
	public function getSuggestionQuery() {
		return $this->useOverriddenSuggestion ? $this->overriddenSuggestion : parent::getSuggestionQuery();
	}

	public function getSuggestionSnippet() {
		return $this->useOverriddenSuggestion ? $this->overriddenSuggestionSnippet : parent::getSuggestionSnippet();
	}

	/**
	 * @return bool
	 */
	public function hasSuggestion() {
		return $this->useOverriddenSuggestion ? $this->overriddenSuggestion != null : parent::hasSuggestion();
	}
}
