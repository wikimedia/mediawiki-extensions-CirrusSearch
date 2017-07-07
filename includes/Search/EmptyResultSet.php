<?php

namespace CirrusSearch\Search;

use SearchResultSet;

/**
 * An empty set of results from Elasticsearch.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */
class EmptyResultSet extends ResultSet {
	/**
	 * Override parent constructor with empty one
	 */
	public function __construct() {
	}

	/**
	 * Is rewriting this query OK?
	 *
	 * @param int $threshold Minimum number of results to reach before rewriting is not allowed.
	 * @return bool True when rewriting this query is allowed
	 */
	public function isQueryRewriteAllowed( $threshold = 1 ) {
		return false;
	}

	/**
	 * @return int
	 */
	public function getTotalHits() {
		return 0;
	}

	/**
	 * @return int
	 */
	public function numRows() {
		return 0;
	}

	/**
	 * @return bool
	 */
	public function hasSuggestion() {
		return false;
	}

	/**
	 * @return string|null
	 */
	public function getSuggestionQuery() {
		return null;
	}

	/**
	 * @return string|null
	 */
	public function getSuggestionSnippet() {
		return null;
	}

	/**
	 * @return false
	 */
	public function next() {
		return false;
	}

	public function rewind() {
	}

	/**
	 * @param ResultSet $res
	 * @param int $type One of SearchResultSet::* constants
	 * @param string $interwiki
	 */
	public function addInterwikiResults( ResultSet $res, $type, $interwiki ) {
		throw new \RuntimeException( "Can't add interwiki results to empty result set" );
	}

	/**
	 * @param int $type
	 * @return SearchResultSet[]
	 */
	public function getInterwikiResults( $type = SearchResultSet::SECONDARY_RESULTS ) {
		return [];
	}

	/**
	 * @param int $type
	 * @return bool
	 */
	public function hasInterwikiResults( $type = SearchResultSet::SECONDARY_RESULTS ) {
		return false;
	}

	/**
	 * @return bool
	 */
	public function searchContainedSyntax() {
		// actually unknown, but close enough
		return false;
	}

	/**
	 * @param string $newQuery
	 * @param string|null $newQuerySnippet
	 */
	public function setRewrittenQuery( $newQuery, $newQuerySnippet=null ) {
		throw new \Exception( "Can't rewrite empty result set" );
	}

	/**
	 * @return bool
	 */
	public function hasRewrittenQuery() {
		return false;
	}

	/**
	 * @return string|null
	 */
	public function getQueryAfterRewrite() {
		return null;
	}

	/**
	 * @return string|null
	 */
	public function getQueryAfterRewriteSnippet() {
		return null;
	}
}
