<?php

namespace CirrusSearch\Search;

use \CirrusSearch\SearchConfig;
use \CirrusSearch\Util;

/**
 * The search context, maintains the state of the current search query.
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

/**
 * The SearchContext stores the various states maintained
 * during the query building process.
 */
class SearchContext {
	/**
	 * @var SearchConfig
	 */
	private $config;

	/**
	 * @var boolean
	 */
	private $searchContainedSyntax = false;

	/**
	 * @var SearchTextQueryBuilderFactory
	 */
	private $searchTextQueryBuilderFactory;

	/**
	 * Builder for the full text query (user query with special syntax extracted)
	 * @var SearchTextQueryBuilder
	 */
	private $searchTextQueryBuilder;



	public function __construct( SearchConfig $config ) {
		$this->config = $config;
		$this->searchTextQueryBuilderFactory = new SearchTextQueryBuilderFactory( $this );
	}

	/**
	 * @return SearchConfig the Cirrus config object
	 */
	public function getConfig() {
		return $this->config;
	}

	/**
	 * @return boolean true if leading wildcards are allowed
	 */
	public function isAllowLeadingWildcards() {
		return $this->config->get( 'CirrusSearchAllowLeadingWildcard' );
	}

	/**
	 * @return array the CommonTermsQuery profile
	 */
	public function getCommonTermsQueryProfile() {
		return $this->config->getElement( 'CirrusSearchCommonTermsQueryProfile' );
	}

	/**
	 * @return true if CommonTermsQuery is allowed
	 */
	public function isUseCommonTermsQuery() {
		return $this->config->get('CirrusSearchUseCommonTermsQuery' );
	}

	/**
	 * @return true if we can use the safer query from the wikimedia extra
	 * plugin
	 */
	public function isUseSafer() {
		return ( !is_null( $this->config->getElement( 'CirrusSearchWikimediaExtraPlugin', 'safer' ) ) );
	}

	/**
	 * @param string $query
	 * @param boolean $isRescore
	 * @return \Elastica\Query\Simple
	 */
	public function wrapInSaferIfPossible( $query, $isRescore ) {
		// @todo: move this code to a common base class when Filters is refactored as non-static
		$saferQuery = $this->config->getElement( 'CirrusSearchWikimediaExtraPlugin', 'safer' );
		if ( is_null( $saferQuery ) ) {
			return $query;
		}
		$saferQuery[ 'query' ] = $query->toArray();
		$tooLargeAction = $isRescore ? 'convert_to_match_all_query' : 'convert_to_term_queries';
		$saferQuery[ 'phrase' ][ 'phrase_too_large_action' ] = $tooLargeAction;
		return new \Elastica\Query\Simple( array( 'safer' => $saferQuery ) );
	}

	/**
	 * @return true if the query contains special syntax
	 */
	public function isSearchContainedSyntax() {
		return $this->searchContainedSyntax;
	}

	/**
	 * @return true if the query contains special syntax
	 */
	public function setSearchContainedSyntax( $searchContainedSyntax ) {
		$this->searchContainedSyntax = $searchContainedSyntax;
	}

	/**
	 * @return SearchTextQueryBuilder
	 */
	public function searchTextQueryBuilder( $queryStringQueryString ) {
		return $this->searchTextQueryBuilderFactory->getBuilder( $queryStringQueryString );
	}
}
