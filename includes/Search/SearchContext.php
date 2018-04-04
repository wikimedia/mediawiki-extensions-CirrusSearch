<?php

namespace CirrusSearch\Search;

use CirrusSearch\OtherIndexes;
use CirrusSearch\Profile\SearchProfileService;
use CirrusSearch\Search\Rescore\BoostFunctionBuilder;
use CirrusSearch\Search\Rescore\RescoreBuilder;
use CirrusSearch\SearchConfig;
use CirrusSearch\WarningCollector;
use Elastica\Aggregation\AbstractAggregation;
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
class SearchContext implements WarningCollector {
	/**
	 * @var SearchConfig
	 */
	private $config;

	/**
	 * @var int[]|null list of namespaces
	 */
	private $namespaces;

	/**
	 * @var string
	 */
	private $profileContext = SearchProfileService::CONTEXT_DEFAULT;

	/**
	 * @var array|null list of boost templates extracted from the query string
	 */
	private $boostTemplatesFromQuery;

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
	 * @var string|array rescore profile to use
	 */
	private $rescoreProfile;

	/**
	 * @var BoostFunctionBuilder[] Extra scoring builders to use.
	 */
	private $extraScoreBuilders = [];

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
	 * @var AbstractQuery[] List of filters that query results must match
	 */
	private $filters = [];

	/**
	 * @var AbstractQuery[] List of filters that query results must not match
	 */
	private $notFilters = [];

	/**
	 * @var array[][] $config List of configurations for highlighting additional
	 *  fields such as source_text. Passed to ResultType::getHighlightingConfiguration
	 *  to generate final highlighting configuration.
	 */
	private $extraHighlightFields = [];

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
	 * @var AbstractQuery|null phrase rescore query
	 */
	private $phraseRescoreQuery;

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
	 * @var string The users search term with keywords removed
	 */
	private $cleanedSearchTerm;

	/**
	 * @var Escaper $escaper
	 */
	private $escaper;

	/**
	 * @var int[] weights of different syntaxes
	 */
	private static $syntaxWeights = [
		// regex is really tough
		'full_text' => 10,
		'regex' => PHP_INT_MAX,
		'more_like' => 100,
		'near_match' => 10,
		'prefix' => 2,
		// Deep category searches
		'deepcategory' => 20,
	];

	/**
	 * @var array[] Warnings to be passed into StatusValue::warning()
	 */
	private $warnings = [];

	/**
	 * @var string name of the fulltext query builder profile
	 */
	private $fulltextQueryBuilderProfile;

	/**
	 * @var bool Have custom options that effect the search results been set
	 *  outside the defaults from config?
	 */
	private $isDirty = false;

	/**
	 * @var ResultsType Type of the result for the context.
	 */
	private $resultsType;

	/**
	 * @var AbstractAggregation[] Aggregations to perform
	 */
	private $aggs = [];

	/**
	 * @param SearchConfig $config
	 * @param int[]|null $namespaces
	 */
	public function __construct( SearchConfig $config, array $namespaces = null ) {
		$this->config = $config;
		/** @suppress PhanDeprecatedProperty */
		$this->namespaces = $namespaces;
		$this->loadConfig();
	}

	/**
	 * Return a copy of this context with a new configuration.
	 *
	 * @param SearchConfig $config The new configuration
	 * @return SearchContext
	 */
	public function withConfig( SearchConfig $config ) {
		$other = clone $this;
		$other->config = $config;
		$other->loadConfig();

		return $other;
	}

	private function loadConfig() {
		$decay = $this->config->get( 'CirrusSearchPreferRecentDefaultDecayPortion' );
		if ( $decay > 0 ) {
			$this->preferRecentDecayPortion = $decay;
			$this->preferRecentHalfLife = $this->config->get( 'CirrusSearchPreferRecentDefaultHalfLife' );
		}
		$this->escaper = new Escaper( $this->config->get( 'LanguageCode' ), $this->config->get( 'CirrusSearchAllowLeadingWildcard' ) );
	}

	public function __clone() {
		if ( $this->mainQuery ) {
			$this->mainQuery = clone $this->mainQuery;
		}
	}

	/**
	 * Have custom options that effect the search results been set outside the
	 * defaults from config?
	 *
	 * @return bool
	 */
	public function isDirty() {
		return $this->isDirty;
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
		$this->isDirty = true;
		$this->namespaces = $namespaces;
	}

	/**
	 * @return string
	 */
	public function getProfileContext() {
		return $this->profileContext;
	}

	/**
	 * @param string $profileContext
	 */
	public function setProfileContext( $profileContext ) {
		$this->isDirty = $this->isDirty || $this->profileContext !== $profileContext;
		$this->profileContext = $profileContext;
	}

	/**
	 * Return the list of boosted templates specified in the user query (special syntax)
	 * null if not used in the query or an empty array if there was a syntax error.
	 * Initialized after special syntax extraction.
	 *
	 * @return array|null of boosted templates, key is the template value is the weight.
	 *  null indicates the default template boosts should be used.
	 */
	public function getBoostTemplatesFromQuery() {
		return $this->boostTemplatesFromQuery;
	}

	/**
	 * @param array|null $boostTemplatesFromQuery boosted templates extracted from query.
	 *  null indicates the default template boosts should be used.
	 */
	public function setBoostTemplatesFromQuery( $boostTemplatesFromQuery ) {
		$this->isDirty = true;
		$this->boostTemplatesFromQuery = $boostTemplatesFromQuery;
	}

	/**
	 * Returns list of boosted templates specified by extra indexes query.
	 *
	 * @return array[] Map from wiki id to list of templates to boost
	 *  within that wiki
	 */
	public function getExtraIndexBoostTemplates() {
		$extraIndexBoostTemplates = [];
		foreach ( $this->getExtraIndices() as $extraIndex ) {
			$extraIndexBoosts = $this->config->getElement( 'CirrusSearchExtraIndexBoostTemplates', $extraIndex );
			if ( isset( $extraIndexBoosts['wiki'], $extraIndexBoosts['boosts'] ) ) {
				$extraIndexBoostTemplates[$extraIndexBoosts['wiki']] = $extraIndexBoosts['boosts'];
			}
		}

		return $extraIndexBoostTemplates;
	}

	/**
	 * Set prefer recent options
	 *
	 * @param float $preferRecentDecayPortion
	 * @param float $preferRecentHalfLife
	 */
	public function setPreferRecentOptions( $preferRecentDecayPortion, $preferRecentHalfLife ) {
		$this->isDirty = true;
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
	 * Parameter used by Search\Rescore\PreferRecentFunctionScoreBuilder
	 *
	 * @return float the decay portion for prefer recent
	 */
	public function getPreferRecentDecayPortion() {
		return $this->preferRecentDecayPortion;
	}

	/**
	 * Parameter used by Search\Rescore\PreferRecentFunctionScoreBuilder
	 *
	 * @return float the half life for prefer recent
	 */
	public function getPreferRecentHalfLife() {
		return $this->preferRecentHalfLife;
	}

	/**
	 * @return string|array the rescore profile to use
	 */
	public function getRescoreProfile() {
		if ( $this->rescoreProfile === null ) {
			$this->rescoreProfile = $this->config->getProfileService()
				->getProfileName( SearchProfileService::RESCORE, $this->profileContext );
		}
		return $this->rescoreProfile;
	}

	/**
	 * @param string|array $rescoreProfile the rescore profile to use
	 */
	public function setRescoreProfile( $rescoreProfile ) {
		$this->isDirty = true;
		$this->rescoreProfile = $rescoreProfile;
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
		$this->isDirty = true;
		$this->resultsPossible = $possible;
	}

	/**
	 * @param string|null $type type of syntax to check, null for any type
	 * @return bool True when the query uses $type kind of syntax
	 */
	public function isSyntaxUsed( $type = null ) {
		if ( $type === null ) {
			return count( $this->syntaxUsed ) > 0;
		}
		return isset( $this->syntaxUsed[$type] );
	}

	/**
	 * @return bool true if a special keyword was used in the query
	 */
	public function isSpecialKeywordUsed() {
		// full_text is not considered a special keyword
		return !empty( array_diff_key( $this->syntaxUsed, [
			'full_text' => true,
			'full_text_simple_match' => true,
			'full_text_querystring' => true,
		] ) );
	}

	/**
	 * @return string[] List of syntax used in the query
	 */
	public function getSyntaxUsed() {
		return array_keys( $this->syntaxUsed );
	}

	/**
	 * @return string Text description of syntax used by query.
	 */
	public function getSyntaxDescription() {
		return implode( ',', $this->getSyntaxUsed() );
	}

	/**
	 * @param string $feature Name of a syntax feature used in the query string
	 * @param int $weight How "complex" is this feature.
	 */
	public function addSyntaxUsed( $feature, $weight = null ) {
		$this->isDirty = true;
		if ( is_null( $weight ) ) {
			if ( isset( self::$syntaxWeights[$feature] ) ) {
				$weight = self::$syntaxWeights[$feature];
			} else {
				$weight = 1;
			}
		}
		$this->syntaxUsed[$feature] = $weight;
	}

	/**
	 * @return string The type of search being performed, ex: full_text, near_match, prefix, etc.
	 * Using getSyntaxUsed() is better in most cases.
	 */
	public function getSearchType() {
		if ( empty( $this->syntaxUsed ) ) {
			return 'full_text';
		}
		arsort( $this->syntaxUsed );
		// Return the first heaviest syntax
		return key( $this->syntaxUsed );
	}

	/**
	 * @return int maximum complexity of the syntax used in search
	 */
	public function getSearchComplexity() {
		if ( empty( $this->syntaxUsed ) ) {
			return 1;
		}
		arsort( $this->syntaxUsed );

		// Return the first heaviest syntax
		return reset( $this->syntaxUsed );
	}

	/**
	 * @param string $type The type of search being performed. ex: full_text, near_match, prefix, etc.
	 * @deprecated Use addSyntaxUsed()
	 */
	public function setSearchType( $type ) {
	}

	/**
	 * @param AbstractQuery $filter Query results must match this filter
	 */
	public function addFilter( AbstractQuery $filter ) {
		$this->isDirty = true;
		$this->filters[] = $filter;
	}

	/**
	 * @param AbstractQuery $filter Query results must not match this filter
	 */
	public function addNotFilter( AbstractQuery $filter ) {
		$this->isDirty = true;
		$this->notFilters[] = $filter;
	}

	/**
	 * @param bool $isFuzzy is this a fuzzy query?
	 */
	public function setFuzzyQuery( $isFuzzy ) {
		$this->isDirty = true;
		$this->fuzzyQuery = $isFuzzy;
	}

	/**
	 * @return bool is this a fuzzy query?
	 */
	public function isFuzzyQuery() {
		$this->isDirty = true;
		return $this->fuzzyQuery;
	}

	/**
	 * @param string $field The field to add highlighting configuration for.
	 * @param array $config Configuration for highlighting the article source. Passed
	 *  to ResultType::getHighlightingConfiguration to generate final highlighting
	 *  configuration.
	 */
	public function addHighlightField( $field, array $config ) {
		$this->isDirty = true;
		$this->extraHighlightFields[$field][] = $config;
	}

	/**
	 * @param AbstractQuery $query Query that should be used for highlighting if different
	 *  from the query used for selecting.
	 */
	public function setHighlightQuery( AbstractQuery $query = null ) {
		$this->isDirty = true;
		$this->highlightQuery = $query;
	}

	/**
	 * @param AbstractQuery $query queries that don't use Elastic's "query
	 * string" query, for more advanced highlighting (e.g. match_phrase_prefix
	 * for regular quoted strings).
	 */
	public function addNonTextHighlightQuery( AbstractQuery $query ) {
		$this->isDirty = true;
		$this->nonTextHighlightQueries[] = $query;
	}

	/**
	 * @param ResultsType $resultsType
	 * @return array|null Highlight portion of query to be sent to elasticsearch
	 */
	public function getHighlight( ResultsType $resultsType ) {
		$highlight = $resultsType->getHighlightingConfiguration( $this->extraHighlightFields );
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
		if ( $this->highlightQuery ) {
			$bool->addShould( $this->highlightQuery );
		}
		foreach ( $this->nonTextHighlightQueries as $nonTextHighlightQuery ) {
			$bool->addShould( $nonTextHighlightQuery );
		}

		return $bool;
	}

	/**
	 * rescore_query has to be in array form before we send it to Elasticsearch but it is way
	 * easier to work with if we leave it in query form until now
	 *
	 * @return array[] Rescore configurations as used by elasticsearch.
	 */
	public function getRescore() {
		$rescores = ( new RescoreBuilder( $this ) )->build();
		$result = [];
		foreach ( $rescores as $rescore ) {
			$rescore['query']['rescore_query'] = $rescore['query']['rescore_query']->toArray();
			$result[] = $rescore;
		}

		return $result;
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
		// This intentionally does not update the dirty state. It's a bit
		// unrelated .. but it has no practical effect on the search it
		// is only used by certain result types to adjust the way output
		// is represented.
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
		$this->isDirty = true;
		$this->suggestSuffixes[] = $suffix;
	}

	/**
	 * @return AbstractQuery The primary query to be sent to elasticsearch. Includes
	 *  the main quedry, non text queries, and any additional filters.
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
		$filters = $this->filters;
		if ( $this->getNamespaces() ) {
			$filters[] = new \Elastica\Query\Terms( 'namespace', $this->getNamespaces() );
		}

		// Wrap $mainQuery in a filtered query if there are any filters
		$unifiedFilter = Filters::unify( $filters, $this->notFilters );
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
		$this->isDirty = true;
		$this->mainQuery = $query;
	}

	/**
	 * @param \Elastica\Query\AbstractQuery $match Queries that don't use Elastic's
	 * "query string" query, for more advanced searching (e.g.
	 *  match_phrase_prefix for regular quoted strings).
	 */
	public function addNonTextQuery( \Elastica\Query\AbstractQuery $match ) {
		$this->isDirty = true;
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
		$this->isDirty = true;
		$this->suggest = $suggest;
	}

	/**
	 * @return bool Should this search limit results to the local wiki? If
	 *  not called the default is false.
	 */
	public function getLimitSearchToLocalWiki() {
		return $this->limitSearchToLocalWiki;
	}

	/**
	 * @param bool $localWikiOnly Should this search limit results to the local wiki? If
	 *  not called the default is false.
	 */
	public function setLimitSearchToLocalWiki( $localWikiOnly ) {
		if ( $localWikiOnly !== $this->limitSearchToLocalWiki ) {
			$this->isDirty = true;
			$this->limitSearchToLocalWiki = $localWikiOnly;
		}
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
		$this->isDirty = true;
		$this->cacheTtl = $ttl;
	}

	/**
	 * @return string the original search term
	 */
	public function getOriginalSearchTerm() {
		return $this->originalSearchTerm;
	}

	/**
	 * Set the original search term
	 * @param string $term
	 */
	public function setOriginalSearchTerm( $term ) {
		// Intentionally does not set dirty to true. This is used only
		// for logging, as of july 2017.
		$this->originalSearchTerm = $term;
	}

	/**
	 * @return string The search term with keywords removed
	 */
	public function getCleanedSearchTerm() {
		return $this->cleanedSearchTerm;
	}

	/**
	 * @param string $term The search term with keywords removed
	 */
	public function setCleanedSearchTerm( $term ) {
		$this->isDirty = true;
		$this->cleanedSearchTerm = $term;
	}

	/**
	 * @return Escaper
	 */
	public function escaper() {
		return $this->escaper;
	}

	/**
	 * @return BoostFunctionBuilder[]
	 */
	public function getExtraScoreBuilders() {
		return $this->extraScoreBuilders;
	}

	/**
	 * Add custom scoring function to the context.
	 * The rescore builder will pick it up.
	 * @param BoostFunctionBuilder $rescore
	 */
	public function addCustomRescoreComponent( BoostFunctionBuilder $rescore ) {
		$this->isDirty = true;
		$this->extraScoreBuilders[] = $rescore;
	}

	/**
	 * @param string $message i18n message key
	 * @param string|null $param1
	 * @param string|null $param2
	 * @param string|null $param3
	 */
	public function addWarning( $message, $param1 = null,  $param2 = null, $param3 = null ) {
		$this->isDirty = true;
		$this->warnings[] = array_filter( func_get_args(), function ( $v ) {
			return $v !== null;
		} );
	}

	/**
	 * @return array[] Array of arrays. Each sub array is a set of values
	 *  suitable for creating an i18n message.
	 */
	public function getWarnings() {
		return $this->warnings;
	}

	/**
	 * @return string the name of the fulltext query builder profile
	 */
	public function getFulltextQueryBuilderProfile() {
		if ( $this->fulltextQueryBuilderProfile === null ) {
			$this->fulltextQueryBuilderProfile = $this->config->getProfileService()
				->getProfileName( SearchProfileService::FT_QUERY_BUILDER, $this->profileContext );
		}
		return $this->fulltextQueryBuilderProfile;
	}

	/**
	 * @param string $profile set the name of the fulltext query builder profile
	 */
	public function setFulltextQueryBuilderProfile( $profile ) {
		$this->isDirty = true;
		$this->fulltextQueryBuilderProfile = $profile;
	}

	/**
	 * @param ResultsType $resultsType results type to return
	 */
	public function setResultsType( $resultsType ) {
		$this->resultsType = $resultsType;
	}

	/**
	 * @return ResultsType $resultsType results type to return
	 */
	public function getResultsType() {
		if ( $this->resultsType === null ) {
			return new FullTextResultsType( FullTextResultsType::HIGHLIGHT_ALL );
		}
		return $this->resultsType;
	}

	/**
	 * Get the list of extra indices to query.
	 * Generally needed to query externilized file index.
	 * Must be called only once the list of namespaces has been set.
	 *
	 * @return string[]
	 * @see OtherIndexes::getExtraIndexesForNamespaces()
	 */
	public function getExtraIndices() {
		if ( $this->getLimitSearchToLocalWiki() || !$this->getNamespaces() ) {
			return [];
		}
		return OtherIndexes::getExtraIndexesForNamespaces(
			$this->getNamespaces()
		);
	}

	/**
	 * Get the phrase rescore query if available
	 * @return AbstractQuery|null
	 */
	public function getPhraseRescoreQuery() {
		return $this->phraseRescoreQuery;
	}

	/**
	 * Set the phrase rescore query
	 * @param AbstractQuery|null $phraseRescoreQuery
	 */
	public function setPhraseRescoreQuery( $phraseRescoreQuery ) {
		$this->phraseRescoreQuery = $phraseRescoreQuery;
		$this->isDirty = true;
	}

	public function addAggregation( AbstractAggregation $agg ) {
		$this->aggs[] = $agg;
		$this->isDirty = true;
	}

	public function getAggregations() {
		return $this->aggs;
	}
}
