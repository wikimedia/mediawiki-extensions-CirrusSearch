<?php

namespace CirrusSearch;

use CirrusSearch\Search\ResultSet;

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
	 * @param array $results
	 * @param bool $withSyntax
	 */
	public function __construct( array $results, $withSyntax = false ) {
		parent::__construct( [], [],
			new \Elastica\ResultSet( new \Elastica\Response( '{}' ),
				new \Elastica\Query(),
				[] ),
			$withSyntax );
		$this->results = $results;
	}

	/**
	 * @param int $numRows
	 * @return DummyResultSet
	 */
	public static function fakeNumRows( $numRows ) {
		return new self( array_fill( 0, $numRows, null ) );
	}

	/**
	 * @param int $numRows
	 * @param string|null $suggestionQuery
	 * @param null $suggestionSnippet
	 * @return DummyResultSet
	 */
	public static function fakeNumRowWithSuggestion( $numRows, $suggestionQuery = null, $suggestionSnippet = null ) {
		$res = self::fakeNumRows( $numRows );
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
