<?php

namespace CirrusSearch\Search;

use HtmlArmor;

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
	 * @param int $type One of the ISearchResultSet::…_RESULTS constants
	 * @param string $interwiki
	 */
	public function addInterwikiResults( CirrusSearchResultSet $res, $type, $interwiki );

	/**
	 * @param string $newQuery
	 * @param HtmlArmor|string|null $newQuerySnippet
	 */
	public function setRewrittenQuery( string $newQuery, $newQuerySnippet = null );

	/**
	 * @param string $suggestionQuery
	 * @param HtmlArmor|string|null $suggestionSnippet
	 */
	public function setSuggestionQuery( string $suggestionQuery, $suggestionSnippet = null );

}
