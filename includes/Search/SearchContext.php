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
	 * @var array of integer (list of namespaces)
	 */
	private $namespaces;

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

	/**
	 * @var array list of boost templates extracted from the query string
	 */
	private $boostTemplatesFromQuery;

	/**
	 * @deprecated use rescore profiles instead
	 * @var boolean do we need to boost links
	 */
	private $boostLinks = false;

	/**
	 * @var float portion of article's score which decays with time.  Defaults to 0 meaning don't decay the score
	 * with time since the last update.
	 */
	private $preferRecentDecayPortion = 0;
	/**
	 * @var float number of days it takes an the portion of an article score that will decay with time
	 * since last update to decay half way.  Defaults to 0 meaning don't decay the score with time.
	 */
	private $preferRecentHalfLife = 0;

	public function __construct( SearchConfig $config, $namespaces ) {
		$this->config = $config;
		$this->searchTextQueryBuilderFactory = new SearchTextQueryBuilderFactory( $this );
		$this->boostLinks = $this->config->get( 'CirrusSearchBoostLinks' );
		$this->namespaces = $namespaces;
	}

	/**
	 * @return SearchConfig the Cirrus config object
	 */
	public function getConfig() {
		return $this->config;
	}

	/**
	 * the namespaces being requested.
	 * NOTE: this value may change during the Searcher process.
	 * @return array of integer
	 */
	public function getNamespaces() {
		return $this->namespaces;
	}

	/**
	 * set the namespaces
	 * @param array $namespaces array of integer
	 */
	public function setNamespaces( $namespaces ) {
		$this->namespaces = $namespaces;
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
	 * @return boolean true if CommonTermsQuery is allowed
	 */
	public function isUseCommonTermsQuery() {
		return $this->config->get('CirrusSearchUseCommonTermsQuery' );
	}

	/**
	 * @return boolean true if we can use the safer query from the wikimedia extra
	 * plugin
	 */
	public function isUseSafer() {
		return ( !is_null( $this->config->getElement( 'CirrusSearchWikimediaExtraPlugin', 'safer' ) ) );
	}

	/**
	 * @return boolean true  if we can use the function score field_value_factor_with_default provided
	 * by the wikimedia extra plugin
	 */
	public function isUseFieldValueFactorWithDefault() {
		return !is_null( $this->config->getElement( 'CirrusSearchWikimediaExtraPlugin', 'field_value_factor_with_default' ) );
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

	/**
	 * Return the list of boosted templates specified in the user query (special syntax)
	 * null if not used in the query or an empty array if there was a syntax error.
	 * Initiliazed after special syntax extraction.
	 * @return array|null of boosted templates, key is the template value is the weight
	 */
	public function getBoostTemplatesFromQuery() {
		return $this->boostTemplatesFromQuery;
	}

	/**
	 * @param array $boostTemplatesFromQuery boosted templates extracted from query
	 */
	public function setBoostTemplatesFromQuery( $boostTemplatesFromQuery ) {
		$this->boostTemplatesFromQuery = $boostTemplatesFromQuery;
	}

	/**
	 * @deprecated use rescore profiles
	 * @param boolean $boostLinks Deactivate IncomingLinksFunctionScoreBuilder if present in the rescore profile
	 */
	public function setBoostLinks( $boostLinks ) {
		$this->boostLinks = $boostLinks;
	}

	/**
	 * @deprecated use custom rescore profile
	 * @return boolean
	 */
	public function isBoostLinks() {
		return $this->boostLinks;
	}

	/**
	 * Set prefer recent options
	 * @param float $preferRecentDecayPortion
	 * @param float $preferRecentHalfLife
	 */
	public function setPreferRecentOptions( $preferRecentDecayPortion, $preferRecentHalfLife ) {
		$this->preferRecentDecayPortion = $preferRecentDecayPortion;
		$this->preferRecentHalfLife = $preferRecentHalfLife;
	}


	/**
	 * @return true if preferRecent options has been set.
	 */
	public function hasPreferRecentOptions() {
		return $this->preferRecentHalfLife > 0 && $this->preferRecentDecayPortion > 0;
	}

	/**
	 * Parameter used by Search\PreferRecentFunctionScoreBuilder
	 * @return float the decay portion for prefer recent
	 */
	public function getPreferRecentDecayPortion() {
		return $this->preferRecentDecayPortion;
	}

	/**
	 * Parameter used by Search\PreferRecentFunctionScoreBuilder
	 * @return float the half life for prefer recent
	 */
	public function getPreferRecentHalfLife() {
		return $this->preferRecentHalfLife;
	}
}
