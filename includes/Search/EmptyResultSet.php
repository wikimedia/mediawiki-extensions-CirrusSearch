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
	 * @param bool $containedSyntax Did the search use special syntax?
	 */
	public function __construct( $containedSyntax = false ) {
		// Skip our direct parent constructor and go straight
		// to grandparent. The parent constructor sources a
		// bunch of info from an elasticsearch result set that
		// we don't have available.
		/** @suppress PhanUndeclaredStaticMethod this is an allowed way to call parent class */
		SearchResultSet::__construct( $containedSyntax );
		$this->results = [];
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
	 * @param string $newQuery
	 * @param string|null $newQuerySnippet
	 */
	public function setRewrittenQuery( $newQuery, $newQuerySnippet = null ) {
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
