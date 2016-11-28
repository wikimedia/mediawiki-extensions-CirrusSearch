<?php

namespace CirrusSearch\Search;

use CirrusSearch\SearchConfig;
use GeoData\Coord;
use Elastica\Query\AbstractQuery;

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
	 * @var int[]|null list of namespaces
	 */
	private $namespaces;

	/**
	 * @var array|null list of boost templates extracted from the query string
	 */
	private $boostTemplatesFromQuery;

	/**
	 * @deprecated use rescore profiles instead
	 * @var bool do we need to boost links
	 */
	private $boostLinks = false;

	/**
	 * @var float portion of article's score which decays with time.  Defaults to 0 meaning don't decay the score
	 *  with time since the last update.
	 */
	private $preferRecentDecayPortion = 0;

	/**
	 * @var float number of days it takes an the portion of an article score that will decay with time
	 *  since last update to decay half way.  Defaults to 0 meaning don't decay the score with time.
	 */
	private $preferRecentHalfLife = 0;

	/**
	 * @var string rescore profile to use
	 */
	private $rescoreProfile;

	/**
	 * @var array[] nested array of arrays. Each child array contains three keys:
	 * coord, radius and weight. Used for geographic radius boosting.
	 */
	private $geoBoosts = [];

	/**
	 * @var bool Could this query possibly return results?
	 */
	private $resultsPossible = true;

	/**
	 * @var string[] List of features in the user suplied query string. Features are
	 *  held in the array key, value is always true.
	 */
	private $syntaxUsed = [];

	/**
	 * @var string The type of search being performed. ex: full_text, near_match, prefix, etc.
	 */
	private $searchType = 'unknown';

	/**
	 * @var AbstractQuery[] List of filters that query results must match
	 */
	private $filters = [];

	/**
	 * @var AbstractQuery[] List of filters that query results must not match
	 */
	private $notFilters = [];

	/**
	 * @var array[] $config List of configurations for highlighting the article
	 *  source. Passed to ResultType::getHighlightingConfiguration to generate
	 *  final highlighting configuration. Empty if source is ignored.
	 */
	private $highlightSource = [];

	/**
	 * @var boolean is this a fuzzy query?
	 */
	private $fuzzyQuery = false;

	/**
	 * @var AbstractQuery|null Query that should be used for highlighting if different
	 *  from the query used for selecting.
	 */
	private $highlightQuery;

	/**
	 * @var AbstractQuery[] queries that don't use Elastic's "query string" query,
	 *  for more advanced highlighting (e.g. match_phrase_prefix for regular
	 *  quoted strings).
	 */
	private $nonTextHighlightQueries = [];

	/**
	 * @var array Set of rescore configurations as used by elasticsearch. The query needs
	 *  to be an Elastica query.
	 */
	private $rescore = [];

	/**
	 * @var string[] array of prefixes that should be prepended to suggestions. Can be added
	 *  to externally and is added to during search syntax parsing.
	 */
	private $suggestPrefixes = [];

	/**
	 * @var string[] array of suffixes that should be prepended to suggestions. Can be added
	 *  to externally and is added to during search syntax parsing.
	 */
	private $suggestSuffixes = [];

	/**
	 * @var AbstractQuery|null main query. null defaults to MatchAll
	 */
	private $mainQuery;

	/**
	 * @var \Elastica\Query\Match[] Queries that don't use Elastic's "query string" query, for
	 *  more advanced searching (e.g. match_phrase_prefix for regular quoted strings).
	 */
	private $nonTextQueries = [];

	/**
	 * @var array|null Configuration for suggest query
	 */
	private $suggest;

	/**
	 * @var bool Should this search limit results to the local wiki?
	 */
	private $limitSearchToLocalWiki = false;

	/**
	 * @var int The number of seconds to cache results for
	 */
	private $cacheTtl = 0;

	/**
	 * @var string The original search
	 */
	private $originalSearchTerm;

	/**
	 * @var Escaper $escaper
	 */
	private $escaper;

	/**
	 * @param SearchConfig $config
	 * @param int[]|null $namespaces
	 */
	public function __construct( SearchConfig $config, array $namespaces = null ) {
		$this->config = $config;
		/** @suppress PhanDeprecatedProperty */
		$this->boostLinks = $this->config->get( 'CirrusSearchBoostLinks' );
		$this->namespaces = $namespaces;
		$this->rescoreProfile = $this->config->get( 'CirrusSearchRescoreProfile' );

		$decay = $this->config->get( 'CirrusSearchPreferRecentDefaultDecayPortion' );
		if ( $decay > 0 ) {
			$this->preferRecentDecayPortion = $decay;
			$this->preferRecentHalfLife = $this->config->get( 'CirrusSearchPreferRecentDefaultHalfLife' );
		}
		$this->escaper = new Escaper( $config->get( 'LanguageCode' ), $config->get( 'CirrusSearchAllowLeadingWildcard' ) );
	}

	public function __clone() {
		if ( $this->mainQuery ) {
			$this->mainQuery = clone $this->mainQuery;
		}
	}

	/**
	 * @return SearchConfig the Cirrus config object
	 */
	public function getConfig() {
		return $this->config;
	}

	/**
	 * mediawiki namespace id's being requested.
	 * NOTE: this value may change during the Searcher process.
	 *
	 * @return int[]|null
	 */
	public function getNamespaces() {
		return $this->namespaces;
	}

	/**
	 * set the mediawiki namespace id's
	 *
	 * @param int[]|null $namespaces array of integer
	 */
	public function setNamespaces( $namespaces ) {
		$this->namespaces = $namespaces;
	}

	/**
	 * Return the list of boosted templates specified in the user query (special syntax)
	 * null if not used in the query or an empty array if there was a syntax error.
	 * Initialized after special syntax extraction.
	 *
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
	 * @param bool $boostLinks Deactivate IncomingLinksFunctionScoreBuilder if present in the rescore profile
	 */
	public function setBoostLinks( $boostLinks ) {
		/** @suppress PhanDeprecatedProperty */
		$this->boostLinks = $boostLinks;
	}

	/**
	 * @deprecated use custom rescore profile
	 * @return bool
	 * @suppress PhanDeprecatedProperty
	 */
	public function isBoostLinks() {
		return $this->boostLinks;
	}

	/**
	 * Set prefer recent options
	 *
	 * @param float $preferRecentDecayPortion
	 * @param float $preferRecentHalfLife
	 */
	public function setPreferRecentOptions( $preferRecentDecayPortion, $preferRecentHalfLife ) {
		$this->preferRecentDecayPortion = $preferRecentDecayPortion;
		$this->preferRecentHalfLife = $preferRecentHalfLife;
	}


	/**
	 * @return bool true if preferRecent options have been set.
	 */
	public function hasPreferRecentOptions() {
		return $this->preferRecentHalfLife > 0 && $this->preferRecentDecayPortion > 0;
	}

	/**
	 * Parameter used by Search\PreferRecentFunctionScoreBuilder
	 *
	 * @return float the decay portion for prefer recent
	 */
	public function getPreferRecentDecayPortion() {
		return $this->preferRecentDecayPortion;
	}

	/**
	 * Parameter used by Search\PreferRecentFunctionScoreBuilder
	 *
	 * @return float the half life for prefer recent
	 */
	public function getPreferRecentHalfLife() {
		return $this->preferRecentHalfLife;
	}

	/**
	 * @return string the rescore profile to use
	 */
	public function getRescoreProfile() {
		return $this->rescoreProfile;
	}

	/**
	 * @param string the rescore profile to use
	 */
	public function setRescoreProfile( $rescoreProfile ) {
		$this->rescoreProfile = $rescoreProfile;
	}

	/**
	 * @return array[] nested array of arrays. Each child array contains three keys:
	 * coord, radius and weight
	 */
	public function getGeoBoosts() {
		return $this->geoBoosts;
	}

	/**
	 * @param Coord $coord Coordinates to boost near
	 * @param int $radius radius to boost within, in meters
	 * @param float $weight Number to multiply score by when within radius
	 */
	public function addGeoBoost( Coord $coord, $radius, $weight ) {
		$this->geoBoosts[] = [
			'coord' => $coord,
			'radius' => $radius,
			'weight' => $weight,
		];
	}

	/**
	 * @return bool Could this query possibly return results?
	 */
	public function areResultsPossible() {
		return $this->resultsPossible;
	}

	/**
	 * @param bool $possible Could this query possible return results? Defaults to true
	 *  if not called.
	 */
	public function setResultsPossible( $possible ) {
		$this->resultsPossible = $possible;
	}

	/**
	 * @var string|null $type type of syntax to check, null for any type
	 * @return bool True when the query uses $type kind of special syntax
	 */
	public function isSyntaxUsed( $type = null ) {
		if ( $type === null ) {
			return count( $this->syntaxUsed ) > 0;
		}
		return isset( $this->syntaxUsed[$type] );
	}

	/**
	 * @return string[] List of special syntax used in the query
	 */
	public function getSyntaxUsed() {
		return array_keys( $this->syntaxUsed );
	}

	/**
	 * @param string $feature Name of a syntax feature used in the query string
	 */
	public function addSyntaxUsed( $feature ) {
		$this->syntaxUsed[$feature] = true;
	}

	/**
	 * @return string The type of search being performed, ex: full_text, near_match, prefix, etc.
	 * @todo It might be possible to determine this based on the features used.
	 */
	public function getSearchType() {
		return $this->searchType;
	}

	/**
	 * @param string $type The type of search being performed. ex: full_text, near_match, prefix, etc.
	 */
	public function setSearchType( $type ) {
		$this->searchType = $type;
	}

	/**
	 * @param AbstractQuery $filter Query results must match this filter
	 */
	public function addFilter( AbstractQuery $filter ) {
		$this->filters[] = $filter;
	}

	/**
	 * @param AbstractQuery $filter Query results must not match this filter
	 */
	public function addNotFilter( AbstractQuery $filter ) {
		$this->notFilters[] = $filter;
	}

	/**
	 * @param bool $isFuzzy is this a fuzzy query?
	 */
	public function setFuzzyQuery( $isFuzzy ) {
		$this->fuzzyQuery = $isFuzzy;
	}

	/**
	 * @return bool is this a fuzzy query?
	 */
	public function isFuzzyQuery() {
		return $this->fuzzyQuery;
	}

	/**
	 * @param array $config Configuration for highlighting the article source. Passed
	 *  to ResultType::getHighlightingConfiguration to generate final highlighting
	 *  configuration.
	 */
	public function addHighlightSource( array $config ) {
		$this->highlightSource[] = $config;
	}

	/**
	 * @param AbstractQuery Query that should be used for highlighting if different
	 *  from the query used for selecting.
	 */
	public function setHighlightQuery( AbstractQuery $query ) {
		$this->highlightQuery = $query;
	}

	/**
	 * @param AbstractQuery $query queries that don't use Elastic's "query
	 * string" query, for more advanced highlighting (e.g. match_phrase_prefix
	 * for regular quoted strings).
	 */
	public function addNonTextHighlightQuery( AbstractQuery $query ) {
		$this->nonTextHighlightQueries[] = $query;
	}

	/**
	 * @return array|null Highlight portion of query to be sent to elasticsearch
	 */
	public function getHighlight( ResultsType $resultsType ) {
		$highlight = $resultsType->getHighlightingConfiguration( $this->highlightSource );
		if ( !$highlight ) {
			return null;
		}
		if ( $this->fuzzyQuery ) {
			$highlight['fields'] = array_filter(
				$highlight['fields'],
				function ( $field ) {
					return $field['type'] !== 'plain';
				}
			);
		}
		$query = $this->getHighlightQuery();
		if ( $query ) {
			$highlight['highlight_query'] = $query->toArray();
		}

		return $highlight;
	}

	/**
	 * @return AbstractQuery|null Query that should be used for highlighting if different
	 *  from the query used for selecting.
	 */
	private function getHighlightQuery() {
		if ( empty( $this->nonTextHighlightQueries ) ) {
			return $this->highlightQuery;
		}

		$bool = new \Elastica\Query\BoolQuery();
		if ( $this->highlightQuery) {
			$bool->addShould( $this->highlightQuery );
		}
		foreach ( $this->nonTextHighlightQueries as $nonTextHighlightQuery ) {
			$bool->addShould( $nonTextHighlightQuery );
		}

		return $bool;
	}

	/**
	 * @return bool True if rescore queries are attached
	 */
	public function hasRescore() {
		return count( $this->rescore ) > 0;
	}

	/**
	 * rescore_query has to be in array form before we send it to Elasticsearch but it is way
	 * easier to work with if we leave it in query form until now
	 *
	 * @return array[] Rescore configurations as used by elasticsearch.
	 */
	public function getRescore() {
		$result = [];
		foreach ( $this->rescore as $rescore ) {
			$rescore['query']['rescore_query'] = $rescore['query']['rescore_query']->toArray();
			$result[] = $rescore;
		}

		return $result;
	}

	/**
	 * @param array[] $rescore Rescore configuration as used by elasticsearch. The query needs
	 *  to be an Elastica query.
	 */
	public function addRescore( array $rescore ) {
		$this->rescore[] = $rescore;
	}

	/**
	 * Remove all rescores from the query. Used when it is known that extra work scoring
	 * results will not be useful or necessary. Only effective if done *after* all rescores
	 * have been added.
	 */
	public function clearRescore() {
		$this->rescore = [];
	}

	/**
	 * @param array[] $rescores A set of rescore configurations as used by elasticsearch. The
	 *  query needs to be an Elastica query.
	 */
	public function mergeRescore( $rescores ) {
		$this->rescore = array_merge( $this->rescore, $rescores );
	}

	/**
	 * @return string[] List of prefixes to be prepended to suggestions
	 */
	public function getSuggestPrefixes() {
		return $this->suggestPrefixes;
	}

	/**
	 * @param string $prefix Prefix to be prepended to suggestions
	 */
	public function addSuggestPrefix( $prefix ) {
		$this->suggestPrefixes[] = $prefix;
	}

	/**
	 * @return string[] List of suffixes to be appended to suggestions
	 */
	public function getSuggestSuffixes() {
		return $this->suggestSuffixes;
	}

	/**
	 * @param string $suffix Suffix to be appended to suggestions
	 */
	public function addSuggestSuffix( $suffix ) {
		$this->suggestSuffixes[] = $suffix;
	}

	/**
	 * @return AbstractQuery The primary query to be sent to elasticsearch. Includes
	 *  the main query, non text queries, and any additional filters.
	 */
	public function getQuery() {
		if ( empty( $this->nonTextQueries ) ) {
			$mainQuery = $this->mainQuery ?: new \Elastica\Query\MatchAll();
		} else {
			$mainQuery = new \Elastica\Query\BoolQuery();
			if ( $this->mainQuery ) {
				$mainQuery ->addMust( $this->mainQuery );
			}
			foreach ( $this->nonTextQueries as $nonTextQuery ) {
				$mainQuery->addMust( $nonTextQuery );
			}
		}
		// Wrap $mainQuery in a filtered query if there are any filters
		$unifiedFilter = Filters::unify( $this->filters, $this->notFilters );
		if ( $unifiedFilter !== null ) {
			if ( ! ( $mainQuery instanceof \Elastica\Query\BoolQuery ) ) {
				$bool = new \Elastica\Query\BoolQuery();
				$bool->addMust( $mainQuery );
				$mainQuery = $bool;
			}
			$mainQuery->addFilter( $unifiedFilter );
		}


		return $mainQuery;
	}

	/**
	 * @param AbstractQuery $query The primary query to be passed to
	 *  elasticsearch.
	 */
	public function setMainQuery( AbstractQuery $query ) {
		$this->mainQuery = $query;
	}

	/**
	 * @param \Elastica\Query\Match $match Queries that don't use Elastic's
	 * "query string" query, for more advanced searching (e.g.
	 *  match_phrase_prefix for regular quoted strings).
	 */
	public function addNonTextQuery( \Elastica\Query\Match $match ) {
		$this->nonTextQueries[] = $match;
	}

	/**
	 * @return array|null Configuration for suggest query
	 */
	public function getSuggest() {
		return $this->suggest;
	}

	/**
	 * @param array $suggest Configuration for suggest query
	 */
	public function setSuggest( array $suggest ) {
		$this->suggest = $suggest;
	}

	/**
	 * @return boolean Should this search limit results to the local wiki? If
	 *  not called the default is false.
	 */
	public function getLimitSearchToLocalWiki() {
		return $this->limitSearchToLocalWiki;
	}

	/**
	 * @param boolean $localWikiOnly Should this search limit results to the local wiki? If
	 *  not called the default is false.
	 */
	public function setLimitSearchToLocalWiki( $localWikiOnly ) {
		$this->limitSearchToLocalWiki = $localWikiOnly;
	}

	/**
	 * @return int The number of seconds to cache results for
	 */
	public function getCacheTtl() {
		return $this->cacheTtl;
	}

	/**
	 * @param int $ttl The number of seconds to cache results for
	 */
	public function setCacheTtl( $ttl ) {
		$this->cacheTtl = $ttl;
	}

	/**
	 * @return string the original search term
	 */
	public function getOriginalSearchTerm() {
		return $this->originalSearchTerm;
	}

	/**
	 * @param string set the original search term
	 */
	public function setOriginalSearchTerm( $term ) {
		$this->originalSearchTerm = $term;
	}

	/**
	 * @return Escaper
	 */
	public function escaper() {
		return $this->escaper;
	}
}
