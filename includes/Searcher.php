<?php

namespace CirrusSearch;
use Elastica;
use \CirrusSearch;
use \CirrusSearch\Extra\Filter\SourceRegex;
use \CirrusSearch\Search\Escaper;
use \CirrusSearch\Search\Filters;
use \CirrusSearch\Search\FullTextResultsType;
use \CirrusSearch\Search\IdResultsType;
use \CirrusSearch\Search\ResultsType;
use \Language;
use \MWNamespace;
use \ProfileSection;
use \RequestContext;
use \Sanitizer;
use \SearchResultSet;
use \Status;
use \Title;
use \UsageException;

/**
 * Performs searches using Elasticsearch.  Note that each instance of this class
 * is single use only.
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
class Searcher extends ElasticsearchIntermediary {
	const SUGGESTION_HIGHLIGHT_PRE = '<em>';
	const SUGGESTION_HIGHLIGHT_POST = '</em>';
	const HIGHLIGHT_PRE = '<span class="searchmatch">';
	const HIGHLIGHT_POST = '</span>';
	const HIGHLIGHT_REGEX = '/<span class="searchmatch">.*?<\/span>/';
	const MORE_LIKE_THESE_NONE = 0;
	const MORE_LIKE_THESE_ONLY_WIKIBASE = 1;

	/**
	 * Maximum title length that we'll check in prefix and keyword searches.
	 * Since titles can be 255 bytes in length we're setting this to 255
	 * characters.
	 */
	const MAX_TITLE_SEARCH = 255;

	/**
	 * Maximum offset depth allowed.  Too deep will cause very slow queries.
	 * 100,000 feels plenty deep.
	 */
	const MAX_OFFSET = 100000;

	/**
	 * @var integer search offset
	 */
	private $offset;

	/**
	 * @var integer maximum number of result
	 */
	private $limit;

	/**
	 * @var array(integer) namespaces in which to search
	 */
	protected $namespaces;

	/**
	 * @var Language language of the wiki
	 */
	private $language;

	/**
	 * @var ResultsType|null type of results.  null defaults to FullTextResultsType
	 */
	private $resultsType;
	/**
	 * @var string sort type
	 */
	private $sort = 'relevance';
	/**
	 * @var array(string) prefixes that should be prepended to suggestions.  Can be added to externally and is added to
	 * during search syntax parsing.
	 */
	private $suggestPrefixes = array();
	/**
	 * @var array(string) suffixes that should be prepended to suggestions.  Can be added to externally and is added to
	 * during search syntax parsing.
	 */
	private $suggestSuffixes = array();


	// These fields are filled in by the particule search methods
	/**
	 * @var string term to search.
	 */
	private $term;
	/**
	 * @var \Elastica\Query\AbstractQuery|null main query.  null defaults to \Elastica\Query\MatchAll
	 */
	private $query = null;
	/**
	 * @var array(\Elastica\Filter\AbstractFilter) filters that MUST hold true of all results
	 */
	private $filters = array();
	/**
	 * @var array(\Elastica\Filter\AbstractFilter) filters that MUST NOT hold true of all results
	 */
	private $notFilters = array();
	private $suggest = null;
	/**
	 * @var array of rescore configurations as used by elasticsearch.  The query needs to be an Elastica query.
	 */
	private $rescore = array();
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
	/**
	 * @var boolean should the query results boost pages with more incoming links.  Default to false.
	 */
	private $boostLinks = false;
	/**
	 * @var array template name to boost multiplier for having a template.  Defaults to none but initialized by
	 * queries that use it to self::getDefaultBoostTemplates() if they need it.  That is too expensive to do by
	 * default though.
	 */
	private $boostTemplates = array();
	/**
	 * @var string index base name to use
	 */
	private $indexBaseName;

	/**
	 * @var boolean is this a fuzzy query?
	 */
	private $fuzzyQuery = false;
	/**
	 * @var boolean did this search contain any special search syntax?
	 */
	private $searchContainedSyntax = false;
	/**
	 * @var null|\Elastica\AbstractQuery query that should be used for highlighting if different from the
	 * query used for selecting.
	 */
	private $highlightQuery = null;
	/**
	 * @var array configuration for highlighting the article source.  Empty if source is ignored.
	 */
	private $highlightSource = array();

	/**
	 * @var Escaper escapes queries
	 */
	private $escaper;

	/**
	 * @var boolean limit the search to the local wiki.  Defaults to false.
	 */
	private $limitSearchToLocalWiki = false;

	/**
	 * @var boolean just return the array that makes up the query instead of searching
	 */
	private $returnQuery = false;

	/**
	 * @var null|array lazily initialized version of $wgCirrusSearchNamespaceWeights with all string keys
	 * translated into integer namespace codes using $this->language.
	 */
	private $normalizedNamespaceWeights = null;

	/**
	 * Constructor
	 * @param int $offset Offset the results by this much
	 * @param int $limit Limit the results to this many
	 * @param array $namespaces Namespace numbers to search
	 * @param User|null $user user for which this search is being performed.  Attached to slow request logs.
	 * @param string $index Base name for index to search from, defaults to wfWikiId()
	 */
	public function __construct( $offset, $limit, $namespaces, $user, $index = false ) {
		global $wgCirrusSearchSlowSearch,
			$wgLanguageCode,
			$wgContLang;

		parent::__construct( $user, $wgCirrusSearchSlowSearch );
		$this->offset = min( $offset, self::MAX_OFFSET );
		$this->limit = $limit;
		$this->namespaces = $namespaces;
		$this->indexBaseName = $index ?: wfWikiId();
		$this->language = $wgContLang;
		$this->escaper = new Escaper( $wgLanguageCode );
	}

	/**
	 * @param ResultsType $resultsType results type to return
	 */
	public function setResultsType( $resultsType ) {
		$this->resultsType = $resultsType;
	}

	/**
	 * @var boolean just return the array that makes up the query instead of searching
	 */
	public function setReturnQuery( $returnQuery ) {
		$this->returnQuery = $returnQuery;
	}

	/**
	 * Set the type of sort to perform.  Must be 'relevance', 'title_asc', 'title_desc'.
	 * @param string sort type
	 */
	public function setSort( $sort ) {
		$this->sort = $sort;
	}

	/**
	 * Should this search limit results to the local wiki?  If not called the default is false.
	 * @param boolean $limitSearchToLocalWiki should the results be limited?
	 */
	public function limitSearchToLocalWiki( $limitSearchToLocalWiki ) {
		$this->limitSearchToLocalWiki = $limitSearchToLocalWiki;
	}

	/**
	 * Perform a "near match" title search which is pretty much a prefix match without the prefixes.
	 * @param string $search text by which to search
	 * @return Status(mixed) status containing results defined by resultsType on success
	 */
	public function nearMatchTitleSearch( $search ) {
		global $wgCirrusSearchAllFields;

		$profiler = new ProfileSection( __METHOD__ );

		self::checkTitleSearchRequestLength( $search );

		// Elasticsearch seems to have trouble extracting the proper terms to highlight
		// from the default query we make so we feed it exactly the right query to highlight.
		$this->highlightQuery = new \Elastica\Query\MultiMatch();
		$this->highlightQuery->setQuery( $search );
		$this->highlightQuery->setFields( array(
			'title.near_match', 'redirect.title.near_match',
			'title.near_match_asciifolding', 'redirect.title.near_match_asciifolding',
		) );
		if ( $wgCirrusSearchAllFields[ 'use' ] ) {
			// Inseat of using the highlight query we need to make one like it that uses the all_near_match field.
			$allQuery = new \Elastica\Query\MultiMatch();
			$allQuery->setQuery( $search );
			$allQuery->setFields( array( 'all_near_match', 'all_near_match.asciifolding' ) );
			$this->filters[] = new \Elastica\Filter\Query( $allQuery );
		} else {
			$this->filters[] = new \Elastica\Filter\Query( $this->highlightQuery );
		}

		return $this->search( 'near_match', $search );
	}

	/**
	 * Perform a prefix search.
	 * @param string $search text by which to search
	 * @param Status(mixed) status containing results defined by resultsType on success
	 */
	public function prefixSearch( $search ) {
		global $wgCirrusSearchPrefixSearchStartsWithAnyWord;

		$profiler = new ProfileSection( __METHOD__ );

		self::checkTitleSearchRequestLength( $search );

		if ( $wgCirrusSearchPrefixSearchStartsWithAnyWord ) {
			$match = new \Elastica\Query\Match();
			$match->setField( 'title.word_prefix', array(
				'query' => $search,
				'analyzer' => 'plain',
				'operator' => 'and',
			) );
			$this->filters[] = new \Elastica\Filter\Query( $match );
		} else {
			// Elasticsearch seems to have trouble extracting the proper terms to highlight
			// from the default query we make so we feed it exactly the right query to highlight.
			$this->query = new \Elastica\Query\MultiMatch();
			$this->query->setQuery( $search );
			$this->query->setFields( array(
				'title.prefix^10', 'redirect.title.prefix^10',
				'title.prefix_asciifolding', 'redirect.title.prefix_asciifolding'
			) );
		}
		$this->boostTemplates = self::getDefaultBoostTemplates();
		$this->boostLinks = true;

		return $this->search( 'prefix', $search );
	}

	/**
	 * Perform a random search
	 * @param int $seed Seed for the random number generator
	 * @param Status(mixed) status containing results defined by resultsType on success
	 */
	public function randomSearch( $seed ) {
		$profiler = new ProfileSection( __METHOD__ );

		$this->setResultsType( new IdResultsType() );
		$this->sort = 'random';

		return $this->search( 'random', $seed );
	}

	/**
	 * @param string $suggestPrefix prefix to be prepended to suggestions
	 */
	public function addSuggestPrefix( $suggestPrefix ) {
		$this->suggestPrefixes[] = $suggestPrefix;
	}

	/**
	 * Search articles with provided term.
	 * @param $term string term to search
	 * @param boolean $showSuggestion should this search suggest alternative searches that might be better?
	 * @param Status(mixed) status containing results defined by resultsType on success
	 */
	public function searchText( $term, $showSuggestion ) {
		global $wgCirrusSearchPhraseRescoreBoost,
			$wgCirrusSearchPhraseRescoreWindowSize,
			$wgCirrusSearchPreferRecentDefaultDecayPortion,
			$wgCirrusSearchPreferRecentDefaultHalfLife,
			$wgCirrusSearchNearMatchWeight,
			$wgCirrusSearchStemmedWeight,
			$wgCirrusSearchPhraseSlop,
			$wgCirrusSearchBoostLinks,
			$wgCirrusSearchAllFields,
			$wgCirrusSearchAllFieldsForRescore;

		$profiler = new ProfileSection( __METHOD__ );

		// Transform Mediawiki specific syntax to filters and extra (pre-escaped) query string
		$searcher = $this;
		$originalTerm = $term;
		$searchContainedSyntax = false;
		$this->term = trim( $term );
		$this->boostLinks = $wgCirrusSearchBoostLinks;
		$searchType = 'full_text';
		// Handle title prefix notation
		wfProfileIn( __METHOD__ . '-prefix-filter' );
		$prefixPos = strpos( $this->term, 'prefix:' );
		if ( $prefixPos !== false ) {
			$value = substr( $this->term, 7 + $prefixPos );
			$value = trim( $value, '"' ); // Trim quotes in case the user wanted to quote the prefix
			if ( strlen( $value ) > 0 ) {
				$searchContainedSyntax = true;
				$this->term = substr( $this->term, 0, max( 0, $prefixPos - 1 ) );
				$this->suggestSuffixes[] = ' prefix:' . $value;
				// Suck namespaces out of $value
				$cirrusSearchEngine = new CirrusSearch();
				$value = trim( $cirrusSearchEngine->replacePrefixes( $value ) );
				$this->namespaces = $cirrusSearchEngine->namespaces;
				// If the namespace prefix wasn't the entire prefix filter then add a filter for the title
				if ( strpos( $value, ':' ) !== strlen( $value ) - 1 ) {
					$value = str_replace( '_', ' ', $value );
					$prefixQuery = new \Elastica\Query\Match();
					$prefixQuery->setFieldQuery( 'title.prefix', $value );
					$this->filters[] = new \Elastica\Filter\Query( $prefixQuery );
				}
			}
		}
		wfProfileOut( __METHOD__ . '-prefix-filter' );

		wfProfileIn( __METHOD__ . '-prefer-recent' );
		$preferRecentDecayPortion = $wgCirrusSearchPreferRecentDefaultDecayPortion;
		$preferRecentHalfLife = $wgCirrusSearchPreferRecentDefaultHalfLife;
		// Matches "prefer-recent:" and then an optional floating point number <= 1 but >= 0 (decay
		// portion) and then an optional comma followed by another floating point number >= 0 (half life)
		$this->extractSpecialSyntaxFromTerm(
			'/prefer-recent:(1|(?:0?(?:\.[0-9]+)?))?(?:,([0-9]*\.?[0-9]+))? ?/',
			function ( $matches ) use ( &$preferRecentDecayPortion, &$preferRecentHalfLife,
					&$searchContainedSyntax ) {
				global $wgCirrusSearchPreferRecentUnspecifiedDecayPortion;
				if ( isset( $matches[ 1 ] ) && strlen( $matches[ 1 ] ) ) {
					$preferRecentDecayPortion = floatval( $matches[ 1 ] );
				} else {
					$preferRecentDecayPortion = $wgCirrusSearchPreferRecentUnspecifiedDecayPortion;
				}
				if ( isset( $matches[ 2 ] ) ) {
					$preferRecentHalfLife = floatval( $matches[ 2 ] );
				}
				$searchContainedSyntax = true;
				return '';
			}
		);
		$this->preferRecentDecayPortion = $preferRecentDecayPortion;
		$this->preferRecentHalfLife = $preferRecentHalfLife;
		wfProfileOut( __METHOD__ . '-prefer-recent' );

		wfProfileIn( __METHOD__ . '-local' );
		$this->extractSpecialSyntaxFromTerm(
			'/^\s*local:/',
			function ( $matches ) use ( $searcher ) {
				$searcher->limitSearchToLocalWiki( true );
				return '';
			}
		);
		wfProfileOut( __METHOD__ . '-local' );

		// Handle other filters
		wfProfileIn( __METHOD__ . '-other-filters' );
		$filters = $this->filters;
		$notFilters = $this->notFilters;
		$boostTemplates = self::getDefaultBoostTemplates();
		$highlightSource = array();
		$this->extractSpecialSyntaxFromTerm(
			'/(?<not>-)?insource:\/(?<pattern>(?:[^\\\\\/]|\\\\.)+)\/(?<insensitive>i)? ?/',
			function ( $matches ) use ( $searcher, &$filters, &$notFilters, &$searchContainedSyntax, &$searchType, &$highlightSource ) {
				global $wgLanguageCode,
					$wgCirrusSearchWikimediaExtraPlugin,
					$wgCirrusSearchEnableRegex,
					$wgCirrusSearchRegexMaxDeterminizedStates;

				if ( !$wgCirrusSearchEnableRegex ) {
					return;
				}

				$searchContainedSyntax = true;
				$searchType = 'regex';
				$insensitive = !empty( $matches[ 'insensitive' ] );

				$filterDestination = &$filters;
				if ( !empty( $matches[ 'not' ] ) ) {
					$filterDestination = &$notFilters;
				} else {
					$highlightSource[] = array(
						'pattern' => $matches[ 'pattern' ],
						'locale' => $wgLanguageCode,
						'insensitive' => $insensitive,
					);
				}
				if ( isset( $wgCirrusSearchWikimediaExtraPlugin[ 'regex' ] ) &&
						in_array( 'use', $wgCirrusSearchWikimediaExtraPlugin[ 'regex' ] ) ) {
					$filter = new SourceRegex( $matches[ 'pattern' ], 'source_text', 'source_text.trigram' );
					if ( isset( $wgCirrusSearchWikimediaExtraPlugin[ 'regex' ][ 'max_inspect' ] ) ) {
						$filter->setMaxInspect( $wgCirrusSearchWikimediaExtraPlugin[ 'regex' ][ 'max_inspect' ] );
					} else {
						$filter->setMaxInspect( 10000 );
					}
					$filter->setMaxDeterminizedStates( $wgCirrusSearchRegexMaxDeterminizedStates );
					if ( isset( $wgCirrusSearchWikimediaExtraPlugin[ 'regex' ][ 'max_ngrams_extracted' ] ) ) {
						$filter->setMaxNgramExtracted( $wgCirrusSearchWikimediaExtraPlugin[ 'regex' ][ 'max_ngrams_extracted' ] );
					}
					$filter->setCaseSensitive( !$insensitive );
					$filter->setLocale( $wgLanguageCode );
					$filterDestination[] = $filter;
				} else {
					// Without the extra plugin we need to use groovy to attempt the regex.
					// Its less good but its something.
					$script = <<<GROOVY
import org.apache.lucene.util.automaton.*;
sourceText = _source.get("source_text");
if (sourceText == null) {
	false;
} else {
	if (automaton == null) {
		if (insensitive) {
			locale = new Locale(language);
			pattern = pattern.toLowerCase(locale);
		}
		regexp = new RegExp(pattern, RegExp.ALL ^ RegExp.AUTOMATON);
		automaton = new CharacterRunAutomaton(regexp.toAutomaton());
	}
	if (insensitive) {
		sourceText = sourceText.toLowerCase(locale);
	}
	automaton.run(sourceText);
}

GROOVY;
					$filterDestination[] = new \Elastica\Filter\Script( new \Elastica\Script(
						$script,
						array(
							'pattern' => '.*(' . $matches[ 'pattern' ] . ').*',
							'insensitive' => $insensitive,
							'language' => $wgLanguageCode,
							// These null here creates a slot in which the script will shove
							// an automaton while executing.
							'automaton' => null,
							'locale' => null,
						),
						'groovy'
					) );
				}
			}
		);
		// Match filters that look like foobar:thing or foobar:"thing thing"
		// The {7,15} keeps this from having horrible performance on big strings
		$escaper = $this->escaper;
		$fuzzyQuery = $this->fuzzyQuery;
		$this->extractSpecialSyntaxFromTerm(
			'/(?<key>[a-z\\-]{7,15}):\s*(?<value>"(?:[^"]|(?:(?<=\\\)"))+"|[^ "]+) ?/',
			function ( $matches ) use ( $searcher, $escaper, &$filters, &$notFilters, &$boostTemplates,
					&$searchContainedSyntax, &$fuzzyQuery, &$highlightSource ) {
				$key = $matches['key'];
				$value = $matches['value'];  // Note that if the user supplied quotes they are not removed
				$value = str_replace( '\"', '"', $value );
				$filterDestination = &$filters;
				$keepText = true;
				if ( $key[ 0 ] === '-' ) {
					$key = substr( $key, 1 );
					$filterDestination = &$notFilters;
					$keepText = false;
				}
				switch ( $key ) {
					case 'boost-templates':
						$boostTemplates = Searcher::parseBoostTemplates( trim( $value, '"' ) );
						if ( $boostTemplates === null ) {
							$boostTemplates = Searcher::getDefaultBoostTemplates();
						}
						$searchContainedSyntax = true;
						return '';
					case 'hastemplate':
						$value = trim( $value, '"' );
						// We emulate template syntax here as best as possible,
						// so things in NS_MAIN are prefixed with ":" and things
						// in NS_TEMPLATE don't have a prefix at all. Since we
						// don't actually index templates like that, munge the
						// query here
						if ( strpos( $value, ':' ) === 0 ) {
							$value = substr( $value, 1 );
						} else {
							$title = Title::newFromText( $value );
							if ( $title && $title->getNamespace() == NS_MAIN ) {
								$value = Title::makeTitle( NS_TEMPLATE,
									$title->getDBkey() )->getPrefixedText();
							}
						}
						$filterDestination[] = $searcher->matchPage( 'template', $value );
						$searchContainedSyntax = true;
						return '';
					case 'linksto':
						$filterDestination[] = $searcher->matchPage( 'outgoing_link', $value, true );
						$searchContainedSyntax = true;
						return '';
					case 'incategory':
						$filterDestination[] = $searcher->matchPage( 'category.lowercase_keyword', $value );
						$searchContainedSyntax = true;
						return '';
					case 'insource':
						$field = 'source_text.plain';
						$keepText = false;
						// intentionally fall through
					case 'intitle':
						if ( !isset( $field ) ) {
							$field = 'title';
						}
						list( $queryString, $fuzzyQuery ) = $escaper->fixupWholeQueryString(
							$escaper->fixupQueryStringPart( $value ) );
						$query = new \Elastica\Query\QueryString( $queryString );
						$query->setFields( array( $field ) );
						$query->setDefaultOperator( 'AND' );
						$query->setAllowLeadingWildcard( false );
						$query->setFuzzyPrefixLength( 2 );
						$query->setRewrite( 'top_terms_128' );
						$filterDestination[] = new \Elastica\Filter\Query( $query );
						if ( $key === 'insource' ) {
							$highlightSource[] = array( 'query_string' => $query );
						}
						$searchContainedSyntax = true;
						return $keepText ? "$value " : '';
					default:
						return $matches[0];
				}
			}
		);
		$this->filters = $filters;
		$this->notFilters = $notFilters;
		$this->boostTemplates = $boostTemplates;
		$this->searchContainedSyntax = $searchContainedSyntax;
		$this->fuzzyQuery = $fuzzyQuery;
		$this->highlightSource = $highlightSource;
		wfProfileOut( __METHOD__ . '-other-filters' );

		$this->term = $this->escaper->escapeQuotes( $this->term );

		wfProfileIn( __METHOD__ . '-find-phrase-queries' );
		// Match quoted phrases including those containing escaped quotes
		// Those phrases can optionally be followed by ~ then a number (this is the phrase slop)
		// That can optionally be followed by a ~ (this matches stemmed words in phrases)
		// The following all match: "a", "a boat", "a\"boat", "a boat"~, "a boat"~9, "a boat"~9~, -"a boat", -"a boat"~9~
		$query = self::replacePartsOfQuery( $this->term, '/(?<![\]])(?<negate>-|!)?(?<main>"((?:[^"]|(?:(?<=\\\)"))+)"(?<slop>~[0-9]+)?)(?<fuzzy>~)?/',
			function ( $matches ) use ( $searcher, $escaper, &$phrases ) {
				global $wgCirrusSearchPhraseSlop;
				$negate = $matches[ 'negate' ][ 0 ] ? 'NOT ' : '';
				$main = $escaper->fixupQueryStringPart( $matches[ 'main' ][ 0 ] );
				if ( !isset( $matches[ 'fuzzy' ] ) ) {
					if ( !isset( $matches[ 'slop' ] ) ) {
						$main = $main . '~' . $wgCirrusSearchPhraseSlop[ 'precise' ];
					}
					// Got to collect phrases that don't use the all field so we can highlight them.
					// The highlighter locks phrases to the fields that specify them.  It doesn't do
					// that with terms.
					return array(
						'escaped' => $negate . $searcher->switchSearchToExact( $main, true ),
						'nonAll' => $negate . $searcher->switchSearchToExact( $main, false ),
					);
				}
				return array( 'escaped' => $negate . $main );
			} );
		wfProfileOut( __METHOD__ . '-find-phrase-queries' );
		wfProfileIn( __METHOD__ . '-switch-prefix-to-plain' );
		// Find prefix matches and force them to only match against the plain analyzed fields.  This
		// prevents prefix matches from getting confused by stemming.  Users really don't expect stemming
		// in prefix queries.
		$query = self::replaceAllPartsOfQuery( $query, '/\w+\*(?:\w*\*?)*/u',
			function ( $matches ) use ( $searcher, $escaper ) {
				$term = $escaper->fixupQueryStringPart( $matches[ 0 ][ 0 ] );
				return array(
					'escaped' => $searcher->switchSearchToExact( $term, false ),
					'nonAll' => $searcher->switchSearchToExact( $term, false ),
				);
			} );
		wfProfileOut( __METHOD__ . '-switch-prefix-to-plain' );

		wfProfileIn( __METHOD__ . '-escape' );
		$escapedQuery = array();
		$nonAllQuery = array();
		$nearMatchQuery = array();
		foreach ( $query as $queryPart ) {
			if ( isset( $queryPart[ 'escaped' ] ) ) {
				$escapedQuery[] = $queryPart[ 'escaped' ];
				if ( isset( $queryPart[ 'nonAll' ] ) ) {
					$nonAllQuery[] = $queryPart[ 'nonAll' ];
				} else {
					$nonAllQuery[] = $queryPart[ 'escaped' ];
				}
				continue;
			}
			if ( isset( $queryPart[ 'raw' ] ) ) {
				$fixed = $this->escaper->fixupQueryStringPart( $queryPart[ 'raw' ] );
				$escapedQuery[] = $fixed;
				$nonAllQuery[] = $fixed;
				$nearMatchQuery[] = $queryPart[ 'raw' ];
				continue;
			}
			wfLogWarning( 'Unknown query part:  ' . serialize( $queryPart ) );
		}
		wfProfileOut( __METHOD__ . '-escape' );

		// Actual text query
		list( $queryStringQueryString, $this->fuzzyQuery ) =
			$escaper->fixupWholeQueryString( implode( ' ', $escapedQuery ) );
		// Note that no escaping is required for near_match's match query.
		$nearMatchQuery = implode( ' ', $nearMatchQuery );
		if ( $queryStringQueryString !== '' ) {
			if ( preg_match( '/(?:(?<!\\\\)[?*+~"!|-])|AND|OR|NOT/', $queryStringQueryString ) ) {
				$this->searchContainedSyntax = true;
				// We're unlikey to make good suggestions for query string with special syntax in them....
				$showSuggestion = false;
			}
			wfProfileIn( __METHOD__ . '-build-query' );
			$fields = array_merge(
				$this->buildFullTextSearchFields( 1, '.plain', true ),
				$this->buildFullTextSearchFields( $wgCirrusSearchStemmedWeight, '', true ) );
			$nearMatchFields = $this->buildFullTextSearchFields( $wgCirrusSearchNearMatchWeight,
				'.near_match', true );
			$this->query = $this->buildSearchTextQuery( $fields, $nearMatchFields,
				$queryStringQueryString, $nearMatchQuery );

			// The highlighter doesn't know about the weightinging from the all fields so we have to send
			// it a query without the all fields.  This swaps one in.
			if ( $wgCirrusSearchAllFields[ 'use' ] ) {
				$nonAllFields = array_merge(
					$this->buildFullTextSearchFields( 1, '.plain', false ),
					$this->buildFullTextSearchFields( $wgCirrusSearchStemmedWeight, '', false ) );
				list( $nonAllQueryString, /*_*/ ) = $escaper->fixupWholeQueryString( implode( ' ', $nonAllQuery ) );
				$this->highlightQuery = $this->buildSearchTextQueryForFields( $nonAllFields, $nonAllQueryString, 1 );
			} else {
				$nonAllFields = $fields;
			}

			// Only do a phrase match rescore if the query doesn't include any quotes and has a space.
			// Queries without spaces are either single term or have a phrase query generated.
			// Queries with the quote already contain a phrase query and we can't build phrase queries
			// out of phrase queries at this point.
			if ( $wgCirrusSearchPhraseRescoreBoost > 1.0 &&
					$wgCirrusSearchPhraseRescoreWindowSize &&
					!$this->searchContainedSyntax &&
					strpos( $queryStringQueryString, '"' ) === false &&
					strpos( $queryStringQueryString, ' ' ) !== false ) {

				$rescoreFields = $fields;
				if ( !$wgCirrusSearchAllFieldsForRescore ) {
					$rescoreFields = $nonAllFields;
				}

				$this->rescore[] = array(
					'window_size' => $wgCirrusSearchPhraseRescoreWindowSize,
					'query' => array(
						'rescore_query' => $this->buildSearchTextQueryForFields( $rescoreFields,
							'"' . $queryStringQueryString . '"', $wgCirrusSearchPhraseSlop[ 'boost' ] ),
						'query_weight' => 1.0,
						'rescore_query_weight' => $wgCirrusSearchPhraseRescoreBoost,
					)
				);
			}

			$showSuggestion = $showSuggestion && ($this->offset == 0);

			if ( $showSuggestion ) {
				$this->suggest = array(
					'text' => $this->term,
					'suggest' => $this->buildSuggestConfig( 'suggest' ),
				);
			}
			wfProfileOut( __METHOD__ . '-build-query' );

			$result = $this->search( $searchType, $originalTerm );

			if ( !$result->isOK() && $this->isParseError( $result ) ) {
				wfProfileIn( __METHOD__ . '-degraded-query' );
				// Elasticsearch has reported a parse error and we've already logged it when we built the status
				// so at this point all we can do is retry the query as a simple query string query.
				$this->query = new \Elastica\Query\Simple( array( 'simple_query_string' => array(
					'fields' => $fields,
					'query' => $queryStringQueryString,
					'default_operator' => 'AND',
				) ) );
				$this->rescore = array(); // Not worth trying in this state.
				$result = $this->search( 'degraded_full_text', $originalTerm );
				// If that doesn't work we're out of luck but it should.  There no guarantee it'll work properly
				// with the syntax we've built above but it'll do _something_ and we'll still work on fixing all
				// the parse errors that come in.
				wfProfileOut( __METHOD__ . '-degraded-query' );
			}
		} else {
			$result = $this->search( $searchType, $originalTerm );
			// No need to check for a parse error here because we don't actually create a query for
			// Elasticsearch to parse
		}

		return $result;
	}

	/**
	 * Builds a match query against $field for $title.  $title is munged to make title matching better more
	 * intuitive for users.
	 * @param string $field field containing the title
	 * @param string $title title query text to match against
	 * @param boolean $underscores true if the field contains underscores instead of spaces.  Defaults to false.
	 * @return \Elastica\Filter\Query for matching $title to $field
	 */
	public function matchPage( $field, $title, $underscores = false ) {
		$title = trim( $title, '"' );                // Somtimes title is wrapped in quotes - throw them away.
		if ( $underscores ) {
			$title = str_replace( ' ', '_', $title );
		} else {
			$title = str_replace( '_', ' ', $title );
		}
		$match = new \Elastica\Query\Match();
		$match->setFieldQuery( $field, $title );
		return new \Elastica\Filter\Query( $match );
	}

	/**
	 * Find articles that contain similar text to the provided title array.
	 * @param array(Title) $titles title of articles to search for
	 * @param int $options bitset of options:
	 *  MORE_LIKE_THESE_NONE
	 *  MORE_LIKE_THESE_ONLY_WIKIBASE - filter results to only those containing wikibase items
	 * @return Status(ResultSet)
	 */
	public function moreLikeTheseArticles( $titles, $options = Searcher::MORE_LIKE_THESE_NONE ) {
		global $wgCirrusSearchMoreLikeThisConfig;

		$profiler = new ProfileSection( __METHOD__ );

		// It'd be better to be able to have Elasticsearch fetch this during the query rather than make
		// two passes but it doesn't support that at this point
		$pageIds = array();
		foreach ( $titles as $title ) {
			$pageIds[] = $title->getArticleID();
		}
		$found = $this->get( $pageIds, array( 'text' ) );
		if ( !$found->isOk() ) {
			return $found;
		}
		$found = $found->getValue();
		if ( count( $found ) === 0 ) {
			// If none of the pages are in the index we can't find articles like them
			return Status::newGood( new SearchResultSet() /* empty */ );
		}

		$text = array();
		foreach ( $found as $foundArticle ) {
			$text[] = $foundArticle->text;
		}

		$this->query = new \Elastica\Query\MoreLikeThis();
		$this->query->setParams( $wgCirrusSearchMoreLikeThisConfig );
		$this->query->setLikeText( implode( ' ', $text ) );
		$this->query->setFields( array( 'text' ) );
		$idFilter = new \Elastica\Filter\Ids( null, $pageIds );
		$this->filters[] = new \Elastica\Filter\BoolNot( $idFilter );
		if ( $options & Searcher::MORE_LIKE_THESE_ONLY_WIKIBASE ) {
			$this->filters[] = new \Elastica\Filter\Exists( 'wikibase_item' );
		}

		return $this->search( 'more_like', implode( ', ', $titles ) );
	}

	/**
	 * Get the page with $id.  Note that the result is a status containing _all_ pages found.
	 * It is possible to find more then one page if the page is in multiple indexes.
	 * @param array(int) $pageIds page id
	 * @param array(string)|true|false $sourceFiltering source filtering to apply
	 * @return Status containing pages found, containing an empty array if not found,
	 *    or an error if there was an error
	 */
	public function get( $pageIds, $sourceFiltering ) {
		$profiler = new ProfileSection( __METHOD__ );

		$indexType = $this->pickIndexTypeFromNamespaces();
		$searcher = $this;
		$indexBaseName = $this->indexBaseName;
		return Util::doPoolCounterWork(
			'CirrusSearch-Search',
			function() use ( $searcher, $pageIds, $sourceFiltering, $indexType, $indexBaseName ) {
				try {
					global $wgCirrusSearchClientSideSearchTimeout;
					$searcher->start( "get of $indexType." . implode( ', ', $pageIds ) );
					// Shard timeout not supported on get requests so we just use the client side timeout
					Connection::setTimeout( $wgCirrusSearchClientSideSearchTimeout[ 'default' ] );
					$pageType = Connection::getPageType( $indexBaseName, $indexType );
					$query = new \Elastica\Query( new \Elastica\Query\Ids( null, $pageIds ) );
					$query->setParam( '_source', $sourceFiltering );
					$query->addParam( 'stats', 'get' );
					$resultSet = $pageType->search( $query, array( 'search_type' => 'query_and_fetch' ) );
					return $searcher->success( $resultSet->getResults() );
				} catch ( \Elastica\Exception\NotFoundException $e ) {
					// NotFoundException just means the field didn't exist.
					// It is up to the caller to decide if that is an error.
					return $searcher->success( array() );
				} catch ( \Elastica\Exception\ExceptionInterface $e ) {
					return $searcher->failure( $e );
				}
			});
	}

	public function findNamespace( $name ) {
		$searcher = $this;
		$indexBaseName = $this->indexBaseName;
		return Util::doPoolCounterWork(
			'CirrusSearch-NamespaceLookup',
			function() use ( $searcher, $name, $indexBaseName ) {
				try {
					$searcher->start( "lookup namespace for $name" );
					$pageType = Connection::getNamespaceType( $indexBaseName );
					$match = new \Elastica\Query\Match();
					$match->setField( 'name', $name );
					$query = new \Elastica\Query( $match );
					$query->setParam( '_source', false );
					$query->addParam( 'stats', 'namespace' );
					$resultSet = $pageType->search( $query, array( 'search_type' => 'query_and_fetch' ) );
					return $searcher->success( $resultSet->getResults() );
				} catch ( \Elastica\Exception\ExceptionInterface $e ) {
					return $searcher->failure( $e );
				}
			});
	}

	private function extractSpecialSyntaxFromTerm( $regex, $callback ) {
		$suggestPrefixes = $this->suggestPrefixes;
		$this->term = preg_replace_callback( $regex,
			function ( $matches ) use ( $callback, &$suggestPrefixes ) {
				$result = $callback( $matches );
				if ( $result === '' ) {
					$suggestPrefixes[] = $matches[ 0 ];
				}
				return $result;
			},
			$this->term
		);
		$this->suggestPrefixes = $suggestPrefixes;
	}

	private static function replaceAllPartsOfQuery( $query, $regex, $callable ) {
		$result = array();
		foreach ( $query as $queryPart ) {
			if ( isset( $queryPart[ 'raw' ] ) ) {
				$result = array_merge( $result, self::replacePartsOfQuery( $queryPart[ 'raw' ], $regex, $callable ) );
				continue;
			}
			$result[] = $queryPart;
		}
		return $result;
	}

	private static function replacePartsOfQuery( $queryPart, $regex, $callable ) {
		$destination = array();
		$matches = array();
		$offset = 0;
		while ( preg_match( $regex, $queryPart, $matches, PREG_OFFSET_CAPTURE, $offset ) ) {
			$startOffset = $matches[ 0 ][ 1 ];
			if ( $startOffset > $offset ) {
				$destination[] = array( 'raw' => substr( $queryPart, $offset, $startOffset - $offset ) );
			}

			$callableResult = call_user_func( $callable, $matches );
			if ( $callableResult ) {
				$destination[] = $callableResult;
			}

			$offset = $startOffset + strlen( $matches[ 0 ][ 0 ] );
		}
		if ( $offset < strlen( $queryPart ) ) {
			$destination[] = array( 'raw' => substr( $queryPart, $offset ) );
		}
		return $destination;
	}

	/**
	 * Powers full-text-like searches including prefix search.
	 * @return Status(mixed) results from the query transformed by the resultsType
	 */
	private function search( $type, $for ) {
		global $wgCirrusSearchMoreAccurateScoringMode,
			$wgCirrusSearchSearchShardTimeout,
			$wgCirrusSearchClientSideSearchTimeout;

		$profiler = new ProfileSection( __METHOD__ );

		if ( $this->resultsType === null ) {
			$this->resultsType = new FullTextResultsType( FullTextResultsType::HIGHLIGHT_ALL );
		}
		// Default null queries now so the rest of the method can assume it is not null.
		if ( $this->query === null ) {
			$this->query = new \Elastica\Query\MatchAll();
		}

		$query = new Elastica\Query();
		$query->setParam( '_source', $this->resultsType->getSourceFiltering() );
		$query->setParam( 'fields', $this->resultsType->getFields() );

		$extraIndexes = array();
		$indexType = $this->pickIndexTypeFromNamespaces();
		if ( $this->namespaces ) {
			$extraIndexes = $this->getAndFilterExtraIndexes();
			if ( $this->needNsFilter( $extraIndexes, $indexType ) ) {
				$this->filters[] = new \Elastica\Filter\Terms( 'namespace', $this->namespaces );
			}
		}

		// Wrap $this->query in a filtered query if there are any filters
		$unifiedFilter = Filters::unify( $this->filters, $this->notFilters );
		if ( $unifiedFilter !== null ) {
			$this->query = new \Elastica\Query\Filtered( $this->query, $unifiedFilter );
		}

		// Call installBoosts right after we're done munging the query to include filters
		// so any rescores installBoosts adds to the query are done against filtered results.
		$this->installBoosts();

		$query->setQuery( $this->query );

		$highlight = $this->resultsType->getHighlightingConfiguration( $this->highlightSource );
		if ( $highlight ) {
			// Fuzzy queries work _terribly_ with the plain highlighter so just drop any field that is forcing
			// the plain highlighter all together.  Do this here because this works so badly that no
			// ResultsType should be able to use the plain highlighter for these queries.
			if ( $this->fuzzyQuery ) {
				$highlight[ 'fields' ] = array_filter( $highlight[ 'fields' ], function( $field ) {
					return $field[ 'type' ] !== 'plain';
				});
			}
			if ( $this->highlightQuery ) {
				$highlight[ 'highlight_query' ] = $this->highlightQuery->toArray();
			}
			$query->setHighlight( $highlight );
		}
		if ( $this->suggest ) {
			$query->setParam( 'suggest', $this->suggest );
			$query->addParam( 'stats', 'suggest' );
		}
		if( $this->offset ) {
			$query->setFrom( $this->offset );
		}
		if( $this->limit ) {
			$query->setSize( $this->limit );
		}

		if ( $this->sort != 'relevance' ) {
			$this->rescore = array();
		}

		if ( count( $this->rescore ) ) {
			// rescore_query has to be in array form before we send it to Elasticsearch but it is way easier to work
			// with if we leave it in query for until now
			$modifiedRescore = array();
			foreach ( $this->rescore as $rescore ) {
				$rescore[ 'query' ][ 'rescore_query' ] = $rescore[ 'query' ][ 'rescore_query' ]->toArray();
				$modifiedRescore[] = $rescore;
			}
			$query->setParam( 'rescore', $modifiedRescore );
		}

		$query->addParam( 'stats', $type );
		switch ( $this->sort ) {
		case 'relevance':
			break;  // The default
		case 'title_asc':
			$query->setSort( array( 'title.keyword' => 'asc' ) );
			break;
		case 'title_desc':
			$query->setSort( array( 'title.keyword' => 'desc' ) );
			break;
		case 'incoming_links_asc':
			$query->setSort( array( 'incoming_links' => array(
				'order' => 'asc',
				'missing' => '_first',
			) ) );
			break;
		case 'incoming_links_desc':
			$query->setSort( array( 'incoming_links' => array(
				'order' => 'desc',
				'missing' => '_last',
			) ) );
			break;
		case 'random':
			// Random scoring is funky - you have to wrap the query in a FunctionScore query.
			$funcScore = new \Elastica\Query\FunctionScore();
			$funcScore->setRandomScore( $for );
			$funcScore->setQuery( $this->query );
			$query->setQuery( $funcScore );
			break;
		default:
			wfLogWarning( "Invalid sort type:  $this->sort" );
		}

		$queryOptions = array();
		if ( $wgCirrusSearchMoreAccurateScoringMode ) {
			$queryOptions[ 'search_type' ] = 'dfs_query_then_fetch';
		}

		if ( $type === 'regex' ) {
			$poolCounterType = 'CirrusSearch-Regex';
			$queryOptions[ 'timeout' ] = $wgCirrusSearchSearchShardTimeout[ 'regex' ];
			Connection::setTimeout( $wgCirrusSearchClientSideSearchTimeout[ 'regex' ] );
		} else {
			$poolCounterType = 'CirrusSearch-Search';
			$queryOptions[ 'timeout' ] = $wgCirrusSearchSearchShardTimeout[ 'default' ];
			Connection::setTimeout( $wgCirrusSearchClientSideSearchTimeout[ 'default' ] );
		}

		// Setup the search
		$pageType = Connection::getPageType( $this->indexBaseName, $indexType );
		$search = $pageType->createSearch( $query, $queryOptions );
		foreach ( $extraIndexes as $i ) {
			$search->addIndex( $i );
		}

		$description = "$type search for '$for'";

		if ( $this->returnQuery ) {
			return Status::newGood( array(
				'description' => $description,
				'path' => $search->getPath(),
				'params' => $search->getOptions(),
				'query' => $query->toArray(),
			) );
		}

		// Perform the search
		$searcher = $this;
		wfProfileIn( __METHOD__ . '-execute' );
		$result = Util::doPoolCounterWork(
			$poolCounterType,
			function() use ( $searcher, $search, $description ) {
				try {
					$searcher->start( $description );
					return $searcher->success( $search->search() );
				} catch ( \Elastica\Exception\ExceptionInterface $e ) {
					return $searcher->failure( $e );
				}
			},
			function( $error, $key ) use ( $type, $description ) {
				wfLogWarning( "Pool error on key $key during $description:  $error" );
				if ( $error === 'pool-queuefull' ) {
					if ( $type === 'regex' ) {
						return Status::newFatal( 'cirrussearch-regex-too-busy-error' );
					}
					return Status::newFatal( 'cirrussearch-too-busy-error' );
				}
				return Status::newFatal( 'cirrussearch-backend-error' );
			});
		wfProfileOut( __METHOD__ . '-execute' );
		if ( $result->isOK() ) {
			$responseData = $result->getValue()->getResponse()->getData();
			$result->setResult( true, $this->resultsType->transformElasticsearchResult( $this->suggestPrefixes,
				$this->suggestSuffixes, $result->getValue(), $this->searchContainedSyntax ) );
			if ( $responseData[ 'timed_out' ] ) {
				wfLogWarning( "$description timed out and only returned partial results!" );
				if ( $result->getValue()->numRows() === 0 ) {
					return Status::newFatal( 'cirrussearch-backend-error' );
				} else {
					$result->warning( 'cirrussearch-timed-out' );
				}
			}
		}

		return $result;
	}

	public function getNamespaces() {
		return $this->namespaces;
	}

	private function needNsFilter( $extraIndexes, $indexType ) {
		if ( $extraIndexes ) {
			// We're reaching into another wiki's indexes and we don't know what is there so be defensive.
			return true;
		}
		$nsCount = count( $this->namespaces );
		$validNsCount = count( MWNamespace::getValidNamespaces() );
		if ( $nsCount === $validNsCount ) {
			// We're only on our wiki and we're searching _everything_.
			return false;
		}
		if ( !$indexType ) {
			// We're searching less than everything but we're going across indexes.  Back to the defensive.
			return true;
		}
		$namespacesInIndexType = Connection::namespacesInIndexType( $indexType );
		return $nsCount !== $namespacesInIndexType;
	}

	private function buildSearchTextQuery( $fields, $nearMatchFields, $queryString, $nearMatchQuery ) {
		global $wgCirrusSearchPhraseSlop;

		$queryForMostFields = $this->buildSearchTextQueryForFields( $fields, $queryString,
				$wgCirrusSearchPhraseSlop[ 'default' ] );
		if ( $nearMatchQuery ) {
			// Build one query for the full text fields and one for the near match fields so that
			// the near match can run unescaped.
			$bool = new \Elastica\Query\Bool();
			$bool->setMinimumNumberShouldMatch( 1 );
			$bool->addShould( $queryForMostFields );
			$nearMatch = new \Elastica\Query\MultiMatch();
			$nearMatch->setFields( $nearMatchFields );
			$nearMatch->setQuery( $nearMatchQuery );
			$bool->addShould( $nearMatch );
			return $bool;
		}
		return $queryForMostFields;
	}

	private function buildSearchTextQueryForFields( $fields, $queryString, $phraseSlop ) {
		$query = new \Elastica\Query\QueryString( $queryString );
		$query->setFields( $fields );
		$query->setAutoGeneratePhraseQueries( true );
		$query->setPhraseSlop( $phraseSlop );
		$query->setDefaultOperator( 'AND' );
		$query->setAllowLeadingWildcard( false );
		$query->setFuzzyPrefixLength( 2 );
		$query->setRewrite( 'top_terms_128' );
		return $query;
	}

	/**
	 * Build suggest config for $field.
	 * @var $field string field to suggest against
	 * @return array of Elastica configuration
	 */
	private function buildSuggestConfig( $field ) {
		global $wgCirrusSearchPhraseSuggestMaxErrors;
		global $wgCirrusSearchPhraseSuggestConfidence;
		return array(
			'phrase' => array(
				'field' => $field,
				'size' => 1,
				'max_errors' => $wgCirrusSearchPhraseSuggestMaxErrors,
				'confidence' => $wgCirrusSearchPhraseSuggestConfidence,
				'direct_generator' => array(
					array(
						'field' => $field,
						'suggest_mode' => 'always', // Forces us to generate lots of phrases to try.
						// If a term appears in more then half the docs then don't try to correct it.  This really
						// shouldn't kick in much because we're not looking for misspellings.  We're looking for phrases
						// that can be might off.  Like "noble prize" ->  "nobel prize".  In any case, the default was
						// 0.01 which way too frequently decided not to correct some terms.
						'max_term_freq' => 0.5,
						'prefix_length' => 2,
					),
				),
				'highlight' => array(
					'pre_tag' => self::SUGGESTION_HIGHLIGHT_PRE,
					'post_tag' => self::SUGGESTION_HIGHLIGHT_POST,
				),
			),
		);
	}

	public function switchSearchToExact( $term, $allFieldAllowed ) {
		$exact = join( ' OR ', $this->buildFullTextSearchFields( 1, ".plain:$term", $allFieldAllowed ) );
		return "($exact)";
	}

	/**
	 * Build fields searched by full text search.
	 * @param float $weight weight to multiply by all fields
	 * @param string $fieldSuffix suffux to add to field names
	 * @param boolean $allFieldAllowed can we use the all field?  False for
	 *    collecting phrases for the highlighter.
	 * @return array(string) of fields to query
	 */
	public function buildFullTextSearchFields( $weight, $fieldSuffix, $allFieldAllowed ) {
		global $wgCirrusSearchWeights,
			$wgCirrusSearchAllFields;

		if ( $wgCirrusSearchAllFields[ 'use' ] && $allFieldAllowed ) {
			if ( $fieldSuffix === '.near_match' ) {
				// The near match fields can't shard a root field because field fields nead it -
				// thus no suffix all.
				return array( "all_near_match^${weight}" );
			}
			return array( "all${fieldSuffix}^${weight}" );
		}

		$fields = array();

		// Only title and redirect support near_match so skip it for everything else
		$titleWeight = $weight * $wgCirrusSearchWeights[ 'title' ];
		$redirectWeight = $weight * $wgCirrusSearchWeights[ 'redirect' ];
		if ( $fieldSuffix === '.near_match' ) {
			$fields[] = "title${fieldSuffix}^${titleWeight}";
			$fields[] = "redirect.title${fieldSuffix}^${redirectWeight}";
			return $fields;
		}
		$fields[] = "title${fieldSuffix}^${titleWeight}";
		$fields[] = "redirect.title${fieldSuffix}^${redirectWeight}";
		$categoryWeight = $weight * $wgCirrusSearchWeights[ 'category' ];
		$headingWeight = $weight * $wgCirrusSearchWeights[ 'heading' ];
		$openingTextWeight = $weight * $wgCirrusSearchWeights[ 'opening_text' ];
		$textWeight = $weight * $wgCirrusSearchWeights[ 'text' ];
		$auxiliaryTextWeight = $weight * $wgCirrusSearchWeights[ 'auxiliary_text' ];
		$fields[] = "category${fieldSuffix}^${categoryWeight}";
		$fields[] = "heading${fieldSuffix}^${headingWeight}";
		$fields[] = "opening_text${fieldSuffix}^${openingTextWeight}";
		$fields[] = "text${fieldSuffix}^${textWeight}";
		$fields[] = "auxiliary_text${fieldSuffix}^${auxiliaryTextWeight}";
		if ( !$this->namespaces || in_array( NS_FILE, $this->namespaces ) ) {
			$fileTextWeight = $weight * $wgCirrusSearchWeights[ 'file_text' ];
			$fields[] = "file_text${fieldSuffix}^${fileTextWeight}";
		}
		return $fields;
	}

	/**
	 * Pick the index type to search based on the list of namespaces to search.
	 * @return string|false either an index type or false to use all index types
	 */
	private function pickIndexTypeFromNamespaces() {
		if ( !$this->namespaces ) {
			return false; // False selects all index types
		}

		$indexTypes = array();
		foreach ( $this->namespaces as $namespace ) {
			$indexTypes[] =
				Connection::getIndexSuffixForNamespace( $namespace );
		}
		$indexTypes = array_unique( $indexTypes );
		return count( $indexTypes ) > 1 ? false : $indexTypes[0];
	}

	/**
	 * Retrieve the extra indexes for our searchable namespaces, if any
	 * exist. If they do exist, also add our wiki to our notFilters so
	 * we can filter out duplicates properly.
	 *
	 * @return array(string)
	 */
	protected function getAndFilterExtraIndexes() {
		if ( $this->limitSearchToLocalWiki ) {
			return array();
		}
		$extraIndexes = OtherIndexes::getExtraIndexesForNamespaces( $this->namespaces );
		if ( $extraIndexes ) {
			$this->notFilters[] = new \Elastica\Filter\Term(
				array( 'local_sites_with_dupe' => wfWikiId() ) );
		}
		return $extraIndexes;
	}

	/**
	 * If there is any boosting to be done munge the the current query to get it right.
	 */
	private function installBoosts() {
		global $wgCirrusSearchFunctionRescoreWindowSize,
			$wgCirrusSearchLanguageWeight,
			$wgLanguageCode;

		// Quick note:  At the moment ".isEmpty()" is _much_ faster then ".empty".  Never
		// use ".empty".  See https://github.com/elasticsearch/elasticsearch/issues/5086

		if ( $this->sort !== 'relevance' ) {
			// Boosts are irrelevant if you aren't sorting by, well, relevance
			return;
		}

		$functionScore = new \Elastica\Query\FunctionScore();
		$useFunctionScore = false;

		// Customize score by boosting based on incoming links count
		if ( $this->boostLinks ) {
			$incomingLinks = "(doc['incoming_links'].isEmpty() ? 0 : doc['incoming_links'].value)";
			$scoreBoostGroovy = "log10($incomingLinks + 2)";
			$functionScore->addScriptScoreFunction( new \Elastica\Script( $scoreBoostGroovy, null, 'groovy' ) );
			$useFunctionScore = true;
		}

		// Customize score by decaying a portion by time since last update
		if ( $this->preferRecentDecayPortion > 0 && $this->preferRecentHalfLife > 0 ) {
			// Convert half life for time in days to decay constant for time in milliseconds.
			$decayConstant = log( 2 ) / $this->preferRecentHalfLife / 86400000;
			// e^ct - 1 where t is last modified time - now which is negative
			$exponentialDecayGroovy = "Math.expm1($decayConstant * (doc['timestamp'].value - Instant.now().getMillis()))";
			// p(e^ct - 1)
			if ( $this->preferRecentDecayPortion !== 1.0 ) {
				$exponentialDecayGroovy = "$exponentialDecayGroovy * $this->preferRecentDecayPortion";
			}
			// p(e^ct - 1) + 1 which is easier to calculate than, but reduces to 1 - p + pe^ct
			// Which breaks the score into an unscaled portion (1 - p) and a scaled portion (p)
			$exponentialDecayGroovy = "$exponentialDecayGroovy + 1";
			$functionScore->addScriptScoreFunction( new \Elastica\Script( $exponentialDecayGroovy, null, 'groovy' ) );
			$useFunctionScore = true;
		}

		// Add boosts for pages that contain certain templates
		if ( $this->boostTemplates ) {
			foreach ( $this->boostTemplates as $name => $boost ) {
				$match = new \Elastica\Query\Match();
				$match->setFieldQuery( 'template', $name );
				$filterQuery = new \Elastica\Filter\Query( $match );
				$filterQuery->setCached( true );
				$functionScore->addBoostFactorFunction( $boost, $filterQuery );
			}
			$useFunctionScore = true;
		}

		// Add boosts for namespaces
		$namespacesToBoost = $this->namespaces === null ? MWNamespace::getValidNamespaces() : $this->namespaces;
		if ( $namespacesToBoost ) {
			// Group common weights together and build a single filter per weight
			// to save on filters.
			$weightToNs = array();
			foreach ( $namespacesToBoost as $ns ) {
				$weight = $this->getBoostForNamespace( $ns );
				$weightToNs[ (string)$weight ][] = $ns;
			}
			if ( count( $weightToNs ) > 1 ) {
				unset( $weightToNs[ '1' ] );  // That'd be redundant.
				foreach ( $weightToNs as $weight => $namespaces ) {
					$filter = new \Elastica\Filter\Terms( 'namespace', $namespaces );
					$functionScore->addBoostFactorFunction( $weight, $filter );
					$useFunctionScore = true;
				}
			}
		}

		// Boost pages in a user's language
		// I suppose using $wgLang would've been more evil than this, but
		// only marginally so. Find some real context to use here.
		$userLang = RequestContext::getMain()->getLanguage()->getCode();
		if ( $wgCirrusSearchLanguageWeight['user'] ) {
			$functionScore->addBoostFactorFunction(
				$wgCirrusSearchLanguageWeight['user'],
				new \Elastica\Filter\Term( array( 'language' => $userLang ) )
			);
			$useFunctionScore = true;
		}
		// And a wiki's language, if it's different
		if ( $userLang != $wgLanguageCode && $wgCirrusSearchLanguageWeight['wiki'] ) {
			$functionScore->addBoostFactorFunction(
				$wgCirrusSearchLanguageWeight['wiki'],
				new \Elastica\Filter\Term( array( 'language' => $wgLanguageCode ) )
			);
			$useFunctionScore = true;
		}

		if ( !$useFunctionScore ) {
			// Nothing to do
			return;
		}

		// The function score is done as a rescore on top of everything else
		$this->rescore[] = array(
			'window_size' => $wgCirrusSearchFunctionRescoreWindowSize,
			'query' => array(
				'rescore_query' => $functionScore,
				'query_weight' => 1.0,
				'rescore_query_weight' => 1.0,
				'score_mode' => 'multiply',
			)
		);
	}

	private static function getDefaultBoostTemplates() {
		static $defaultBoostTemplates = null;
		if ( $defaultBoostTemplates === null ) {
			$source = wfMessage( 'cirrussearch-boost-templates' )->inContentLanguage();
			$defaultBoostTemplates = array();
			if( !$source->isDisabled() ) {
				$lines = explode( "\n", $source->plain() );
				$lines = preg_replace( '/#.*$/', '', $lines ); // Remove comments
				$lines = array_map( 'trim', $lines );          // Remove extra spaces
				$lines = array_filter( $lines );               // Remove empty lines
				$defaultBoostTemplates = self::parseBoostTemplates(
					implode( ' ', $lines ) );                  // Now parse the templates
			}
		}
		return $defaultBoostTemplates;
	}

	/**
	 * Parse boosted templates.  Parse failures silently return no boosted templates.
	 * @param string $text text representation of boosted templates
	 * @return array of boosted templates.
	 */
	public static function parseBoostTemplates( $text ) {
		$boostTemplates = array();
		$templateMatches = array();
		if ( preg_match_all( '/([^|]+)\|([0-9]+)% ?/', $text, $templateMatches, PREG_SET_ORDER ) ) {
			foreach ( $templateMatches as $templateMatch ) {
				$boostTemplates[ $templateMatch[ 1 ] ] = floatval( $templateMatch[ 2 ] ) / 100;
			}
		}
		return $boostTemplates;
	}

	/**
	 * Get the weight of a namespace.
	 * @param int $namespace the namespace
	 * @return float the weight of the namespace
	 */
	private function getBoostForNamespace( $namespace ) {
		global $wgCirrusSearchNamespaceWeights,
			$wgCirrusSearchDefaultNamespaceWeight,
			$wgCirrusSearchTalkNamespaceWeight;

		if ( $this->normalizedNamespaceWeights === null ) {
			$this->normalizedNamespaceWeights = array();
			foreach ( $wgCirrusSearchNamespaceWeights as $ns => $weight ) {
				if ( is_string( $ns ) ) {
					$ns = $this->language->getNsIndex( $ns );
					// Ignore namespaces that don't exist.
					if ( $ns === false ) {
						continue;
					}
				}
				// Now $ns should always be an integer.
				$this->normalizedNamespaceWeights[ $ns ] = $weight;
			}
		}

		if ( isset( $this->normalizedNamespaceWeights[ $namespace ] ) ) {
			return $this->normalizedNamespaceWeights[ $namespace ];
		}
		if ( MWNamespace::isSubject( $namespace ) ) {
			if ( $namespace === NS_MAIN ) {
				return 1;
			}
			return $wgCirrusSearchDefaultNamespaceWeight;
		}
		$subjectNs = MWNamespace::getSubject( $namespace );
		if ( isset( $this->normalizedNamespaceWeights[ $subjectNs ] ) ) {
			return $wgCirrusSearchTalkNamespaceWeight * $this->normalizedNamespaceWeights[ $subjectNs ];
		}
		if ( $namespace === NS_TALK ) {
			return $wgCirrusSearchTalkNamespaceWeight;
		}
		return $wgCirrusSearchDefaultNamespaceWeight * $wgCirrusSearchTalkNamespaceWeight;
	}

	private function checkTitleSearchRequestLength( $search ) {
		$requestLength = strlen( $search );
		if ( $requestLength > self::MAX_TITLE_SEARCH ) {
			throw new UsageException( 'Prefix search request was longer longer than the maximum allowed length.' .
				" ($requestLength > " . self::MAX_TITLE_SEARCH . ')', 'request_too_long', 400 );
		}
	}

	/**
	 * Attempt to suck a leading namespace followed by a colon from the query string.  Reaches out to Elasticsearch to
	 * perform normalized lookup against the namespaces.  Should be fast but for the network hop.
	 */
	public function updateNamespacesFromQuery( &$query ) {
		$colon = strpos( $query, ':' );
		if ( $colon === false ) {
			return;
		}
		$namespaceName = substr( $query, 0, $colon );
		$foundNamespace = $this->findNamespace( $namespaceName );
		// Failure case is already logged so just handle success case
		if ( !$foundNamespace->isOK() ) {
			return;
		}
		$foundNamespace = $foundNamespace->getValue();
		if ( count( $foundNamespace ) == 0 ) {
			return;
		}
		$foundNamespace = $foundNamespace[ 0 ];
		$query = substr( $query, $colon + 1 );
		$this->namespaces = array( $foundNamespace->getId() );
	}
}
