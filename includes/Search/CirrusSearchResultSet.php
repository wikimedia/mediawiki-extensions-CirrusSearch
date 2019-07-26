<?php

namespace CirrusSearch\Search;

interface CirrusSearchResultSet extends \ISearchResultSet {
	/**
	 * @return \Elastica\Response|null
	 */
	public function getElasticResponse();

	/**
	 * @return \Elastica\ResultSet|null
	 */
	public function getElasticaResultSet();

	/**
	 * @param CirrusSearchResultSet $res
	 * @param int $type one of searchresultset::* constants
	 * @param string $interwiki
	 */
	public function addInterwikiResults( CirrusSearchResultSet $res, $type, $interwiki );

	/**
	 * @param string $newQuery
	 * @param string|null $newQuerySnippet
	 */
	public function setRewrittenQuery( $newQuery, $newQuerySnippet = null );

	/**
	 * @param string $suggestionQuery
	 * @param string $suggestionSnippet
	 */
	public function setSuggestionQuery( $suggestionQuery, $suggestionSnippet );

}
