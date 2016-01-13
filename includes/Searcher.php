<?php

namespace CirrusSearch;

use Elastica;
use CirrusSearch;
use CirrusSearch\Extra\Filter\SourceRegex;
use CirrusSearch\Search\Escaper;
use CirrusSearch\Search\Filters;
use CirrusSearch\Search\FullTextResultsType;
use CirrusSearch\Search\ResultsType;
use CirrusSearch\Search\RescoreBuilder;
use CirrusSearch\Search\SearchContext;
use ConfigFactory;
use Language;
use MediaWiki\Logger\LoggerFactory;
use MWNamespace;
use SearchResultSet;
use Status;
use Title;
use UsageException;
use User;
use Elastica\Request;
use Elastica\Exception\ResponseException;

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
	 * Maximum length, in characters, allowed in queries sent to searchText.
	 */
	const MAX_TEXT_SEARCH = 300;

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
	 * @var string[] array of prefixes that should be prepended to suggestions.  Can be added to externally and is added to
	 * during search syntax parsing.
	 */
	private $suggestPrefixes = array();
	/**
	 * @var string[] array of suffixes that should be prepended to suggestions.  Can be added to externally and is added to
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
	 * @var \Elastica\Filter\AbstractFilter[] filters that MUST hold true of all results
	 */
	private $filters = array();
	/**
	 * @var \Elastica\Filter\AbstractFilter[] filters that MUST NOT hold true of all results
	 */
	private $notFilters = array();
	private $suggest = null;
	/**
	 * @var array[] of rescore configurations as used by elasticsearch.  The query needs to be an Elastica query.
	 */
	private $rescore = array();
	/**
	 * @var string index base name to use
	 */
	private $indexBaseName;

	/**
	 * @var boolean is this a fuzzy query?
	 */
	private $fuzzyQuery = false;
	/**
	 * @var null|\Elastica\AbstractQuery query that should be used for highlighting if different from the
	 * query used for selecting.
	 */
	private $highlightQuery = null;
	/**
	 * @var array[] configuration for highlighting the article source.  Empty if source is ignored.
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
	 * @var boolean return raw Elasticsearch result instead of processing it
	 */
	private $returnResult = false;

	/**
	 * @var boolean return explanation with results
	 */
	private $returnExplain = false;

	/**
	 * @var \Elastica\Query\Match[] queries that don't use Elastic's "query string" query, for more
	 * advanced searching (e.g. match_phrase_prefix for regular quoted strings).
	 */
	private $nonTextQueries = array();

	/**
	 * @var \Elastica\Query\QueryString[] queries that don't use Elastic's "query string" query, for more
	 * advanced highlighting (e.g. match_phrase_prefix for regular quoted strings).
	 */
	private $nonTextHighlightQueries = array();

	/**
	 * Search environment configuration
	 * @var SearchConfig
	 * Specified as public because of closures. When we move to non-anicent PHP version, can be made protected.
	 */
	public $config;

	/**
	 * @var SearchContext
	 */
	private $searchContext;

	/**
	 * Constructor
	 * @param Connection $conn
	 * @param int $offset Offset the results by this much
	 * @param int $limit Limit the results to this many
	 * @param SearchConfig|null $config Configuration settings
	 * @param int[]|null $namespaces Array of namespace numbers to search or null to search all namespaces.
	 * @param User|null $user user for which this search is being performed.  Attached to slow request logs.
	 * @param string|boolean $index Base name for index to search from, defaults to wfWikiId()
	 */
	public function __construct( Connection $conn, $offset, $limit, SearchConfig $config = null, array $namespaces = null,
		User $user = null, $index = false ) {

		if ( is_null( $config ) ) {
			// @todo connection has an embeded config ... reuse that? somehow should
			// at least ensure they are the same.
			$config = ConfigFactory::getDefaultInstance()->makeConfig( 'CirrusSearch' );
		}

		parent::__construct( $conn, $user, $config->get( 'CirrusSearchSlowSearch' ) );
		$this->config = $config;
		$this->offset = min( $offset, self::MAX_OFFSET );
		$this->limit = $limit;
		$this->indexBaseName = $index ?: $config->getWikiId();
		$this->language = $config->get( 'ContLang' );
		$this->escaper = new Escaper( $config->get( 'LanguageCode' ), $config->get( 'CirrusSearchAllowLeadingWildcard' ) );
		$this->searchContext = new SearchContext( $this->config, $namespaces );
	}

	/**
	 * @param ResultsType $resultsType results type to return
	 */
	public function setResultsType( $resultsType ) {
		$this->resultsType = $resultsType;
	}

	/**
	 * @param boolean $returnQuery just return the array that makes up the query instead of searching
	 */
	public function setReturnQuery( $returnQuery ) {
		$this->returnQuery = $returnQuery;
	}

	/**
	 * @param boolean $dumpResult return raw Elasticsearch result instead of processing it
	 */
	public function setDumpResult( $dumpResult ) {
		$this->returnResult = $dumpResult;
	}

	/**
	 * @param boolean $returnExplain return query explanation
	 */
	public function setReturnExplain( $returnExplain ) {
		$this->returnExplain = $returnExplain;
	}

	/**
	 * Set the type of sort to perform.  Must be 'relevance', 'title_asc', 'title_desc'.
	 * @param string $sort sort type
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
	 * @return Status status containing results defined by resultsType on success
	 */
	public function nearMatchTitleSearch( $search ) {
		self::checkTitleSearchRequestLength( $search );

		// Elasticsearch seems to have trouble extracting the proper terms to highlight
		// from the default query we make so we feed it exactly the right query to highlight.
		$this->highlightQuery = new \Elastica\Query\MultiMatch();
		$this->highlightQuery->setQuery( $search );
		$this->highlightQuery->setFields( array(
			'title.near_match', 'redirect.title.near_match',
			'title.near_match_asciifolding', 'redirect.title.near_match_asciifolding',
		) );
		if ( $this->config->getElement( 'CirrusSearchAllFields', 'use' ) ) {
			// Instead of using the highlight query we need to make one like it that uses the all_near_match field.
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
	 * @return Status status containing results defined by resultsType on success
	 */
	public function prefixSearch( $search ) {
		self::checkTitleSearchRequestLength( $search );

		if ( $search ) {
			if ( $this->config->get( 'CirrusSearchPrefixSearchStartsWithAnyWord' ) ) {
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
				$weights = $this->config->get( 'CirrusSearchPrefixWeights' );
				$this->query->setFields( array(
					'title.prefix^' . $weights[ 'title' ],
					'redirect.title.prefix^' . $weights[ 'redirect' ],
					'title.prefix_asciifolding^' . $weights[ 'title_asciifolding' ],
					'redirect.title.prefix_asciifolding^' . $weights[ 'redirect_asciifolding' ],
				) );
			}
		} else {
			$this->query = new \Elastica\Query\MatchAll();
		}
		// @todo: use dedicated rescore profiles for prefix search.
		$this->searchContext->setBoostLinks( true );

		return $this->search( 'prefix', $search );
	}

	/**
	 * @param string $suggestPrefix prefix to be prepended to suggestions
	 */
	public function addSuggestPrefix( $suggestPrefix ) {
		$this->suggestPrefixes[] = $suggestPrefix;
	}

	/**
	 * Search articles with provided term.
	 * @param string $term term to search
	 * @param boolean $showSuggestion should this search suggest alternative searches that might be better?
	 * @return Status status containing results defined by resultsType on success
	 */
	public function searchText( $term, $showSuggestion ) {
		$checkLengthStatus = self::checkTextSearchRequestLength( $term );
		if ( !$checkLengthStatus->isOk() ) {
			return $checkLengthStatus;
		}

		// Transform Mediawiki specific syntax to filters and extra (pre-escaped) query string
		$searcher = $this;
		$originalTerm = $term;
		$searchContainedSyntax = false;
		$this->term = $term;
		$searchType = 'full_text';
		// Handle title prefix notation
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
				$cirrusSearchEngine->setConnection( $this->connection );
				$value = trim( $cirrusSearchEngine->replacePrefixes( $value ) );
				$this->searchContext->setNamespaces( $cirrusSearchEngine->namespaces );
				// If the namespace prefix wasn't the entire prefix filter then add a filter for the title
				if ( strpos( $value, ':' ) !== strlen( $value ) - 1 ) {
					$value = str_replace( '_', ' ', $value );
					$prefixQuery = new \Elastica\Query\Match();
					$prefixQuery->setFieldQuery( 'title.prefix', $value );
					$this->filters[] = new \Elastica\Filter\Query( $prefixQuery );
				}
			}
		}

		$preferRecentDecayPortion = $this->config->get( 'CirrusSearchPreferRecentDefaultDecayPortion' );
		$preferRecentHalfLife = $this->config->get( 'CirrusSearchPreferRecentDefaultHalfLife' );
		$unspecifiedDecayPortion = $this->config->get( 'CirrusSearchPreferRecentUnspecifiedDecayPortion' );
		// Matches "prefer-recent:" and then an optional floating point number <= 1 but >= 0 (decay
		// portion) and then an optional comma followed by another floating point number >= 0 (half life)
		$this->extractSpecialSyntaxFromTerm(
			'/prefer-recent:(1|0?(?:\.\d+)?)?(?:,(\d*\.?\d+))? ?/',
			function ( $matches ) use ( $unspecifiedDecayPortion, &$preferRecentDecayPortion, &$preferRecentHalfLife,
					&$searchContainedSyntax ) {
				if ( isset( $matches[ 1 ] ) && strlen( $matches[ 1 ] ) ) {
					$preferRecentDecayPortion = floatval( $matches[ 1 ] );
				} else {
					$preferRecentDecayPortion = $unspecifiedDecayPortion;
				}
				if ( isset( $matches[ 2 ] ) ) {
					$preferRecentHalfLife = floatval( $matches[ 2 ] );
				}
				$searchContainedSyntax = true;
				return '';
			}
		);
		$this->searchContext->setPreferRecentOptions( $preferRecentDecayPortion, $preferRecentHalfLife );

		$this->extractSpecialSyntaxFromTerm(
			'/^\s*local:/',
			function ( $matches ) use ( $searcher ) {
				$searcher->limitSearchToLocalWiki( true );
				return '';
			}
		);

		// Handle other filters
		$filters = $this->filters;
		$notFilters = $this->notFilters;
		$highlightSource = array();
		$this->extractSpecialSyntaxFromTerm(
			'/(?<not>-)?insource:\/(?<pattern>(?:[^\\\\\/]|\\\\.)+)\/(?<insensitive>i)? ?/',
			function ( $matches ) use ( $searcher, &$filters, &$notFilters, &$searchContainedSyntax, &$searchType, &$highlightSource ) {

				if ( !$searcher->config->get( 'CirrusSearchEnableRegex' ) ) {
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
						'locale' => $searcher->config->get( 'LanguageCode' ),
						'insensitive' => $insensitive,
					);
				}
				$regex = $searcher->config->getElement( 'CirrusSearchWikimediaExtraPlugin', 'regex' );
				if ( $regex && in_array( 'use', $regex ) ) {
					$filter = new SourceRegex( $matches[ 'pattern' ], 'source_text', 'source_text.trigram' );
					if ( isset( $regex[ 'max_inspect' ] ) ) {
						$filter->setMaxInspect( $regex[ 'max_inspect' ] );
					} else {
						$filter->setMaxInspect( 10000 );
					}
					$filter->setMaxDeterminizedStates( $searcher->config->get( 'CirrusSearchRegexMaxDeterminizedStates' ) );
					if ( isset( $regex[ 'max_ngrams_extracted' ] ) ) {
						$filter->setMaxNgramExtracted( $regex[ 'max_ngrams_extracted' ] );
					}
					$filter->setCaseSensitive( !$insensitive );
					$filter->setLocale( $this->config->get( 'LanguageCode' ) );
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
							'language' => $searcher->config->get( 'LanguageCode' ),
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
		$isEmptyQuery = false;
		$this->extractSpecialSyntaxFromTerm(
			'/(?<key>[a-z\\-]{7,15}):\s*(?<value>"(?<quoted>(?:[^"]|(?<=\\\)")+)"|(?<unquoted>\S+)) ?/',
			function ( $matches ) use ( $searcher, $escaper, &$filters, &$notFilters,
					&$searchContainedSyntax, &$fuzzyQuery, &$highlightSource, &$isEmptyQuery ) {
				$key = $matches['key'];
				$quotedValue = $matches['value'];
				$value = $matches['quoted'] !== ''
					? str_replace( '\"', '"', $matches['quoted'] )
					: $matches['unquoted'];
				$filterDestination = &$filters;
				$keepText = true;
				if ( $key[ 0 ] === '-' ) {
					$key = substr( $key, 1 );
					$filterDestination = &$notFilters;
					$keepText = false;
				}
				switch ( $key ) {
					case 'boost-templates':
						$boostTemplates = Util::parseBoostTemplates( $value );
						$searcher->getSearchContext()->setBoostTemplatesFromQuery( $boostTemplates );
						$searchContainedSyntax = true;
						return '';
					case 'hastemplate':
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
						$categories = array_slice( explode( '|', $value ), 0, $searcher->config->get( 'CirrusSearchMaxIncategoryOptions' ) );
						$categoryFilters = $searcher->matchPageCategories( $categories );
						if ( $categoryFilters === null ) {
							$isEmptyQuery = true;
						} else {
							$filterDestination[] = $categoryFilters;
						}
						$searchContainedSyntax = true;
						return '';
					case 'insource':
						$updateReferences = Filters::insource( $escaper, $searcher->getSearchContext(), $quotedValue );
						$updateReferences( $fuzzyQuery, $filterDestination, $highlightSource, $searchContainedSyntax );
						return '';
					case 'intitle':
						$updateReferences = Filters::intitle( $escaper, $searcher->getSearchContext(), $quotedValue );
						$updateReferences( $fuzzyQuery, $filterDestination, $highlightSource, $searchContainedSyntax );
						return $keepText ? "$quotedValue " : '';
					default:
						return $matches[0];
				}
			}
		);
		if ( $isEmptyQuery ) {
			return Status::newGood( new SearchResultSet( true ) );
		}
		$this->filters = $filters;
		$this->notFilters = $notFilters;
		$this->searchContext->setSearchContainedSyntax( $searchContainedSyntax );
		$this->fuzzyQuery = $fuzzyQuery;
		$this->highlightSource = $highlightSource;

		$this->term = $this->escaper->escapeQuotes( $this->term );
		$this->term = trim( $this->term );

		// Match quoted phrases including those containing escaped quotes
		// Those phrases can optionally be followed by ~ then a number (this is the phrase slop)
		// That can optionally be followed by a ~ (this matches stemmed words in phrases)
		// The following all match: "a", "a boat", "a\"boat", "a boat"~, "a boat"~9, "a boat"~9~, -"a boat", -"a boat"~9~
		$slop = $this->config->get('CirrusSearchPhraseSlop');
		$query = self::replacePartsOfQuery( $this->term, '/(?<![\]])(?<negate>-|!)?(?<main>"((?:[^"]|(?<=\\\)")+)"(?<slop>~\d+)?)(?<fuzzy>~)?/',
			function ( $matches ) use ( $searcher, $escaper, $slop ) {
				$negate = $matches[ 'negate' ][ 0 ] ? 'NOT ' : '';
				$main = $escaper->fixupQueryStringPart( $matches[ 'main' ][ 0 ] );

				if ( !$negate && !isset( $matches[ 'fuzzy' ] ) && !isset( $matches[ 'slop' ] ) &&
						 preg_match( '/^"([^"*]+)[*]"/', $main, $matches ) ) {
					$phraseMatch = new Elastica\Query\Match( );
					$phraseMatch->setFieldQuery( "all.plain", $matches[1] );
					$phraseMatch->setFieldType( "all.plain", "phrase_prefix" );
					$this->nonTextQueries[] = $phraseMatch;

					$phraseHighlightMatch = new Elastica\Query\QueryString( );
					$phraseHighlightMatch->setQuery( $matches[1] . '*' );
					$phraseHighlightMatch->setFields( array( 'all.plain' ) );
					$this->nonTextHighlightQueries[] = $phraseHighlightMatch;

					return array();
				}

				if ( !isset( $matches[ 'fuzzy' ] ) ) {
					if ( !isset( $matches[ 'slop' ] ) ) {
						$main = $main . '~' . $slop[ 'precise' ];
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
		// Find prefix matches and force them to only match against the plain analyzed fields.  This
		// prevents prefix matches from getting confused by stemming.  Users really don't expect stemming
		// in prefix queries.
		$query = self::replaceAllPartsOfQuery( $query, '/\w+\*(?:\w*\*?)*/u',
			function ( $matches ) use ( $searcher, $escaper ) {
				$term = $escaper->fixupQueryStringPart( $matches[ 0 ][ 0 ] );
				return array(
					'escaped' => $searcher->switchSearchToExactForWildcards( $term ),
					'nonAll' => $searcher->switchSearchToExactForWildcards( $term )
				);
			} );

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
			LoggerFactory::getInstance( 'CirrusSearch' )->warning(
				'Unknown query part: {queryPart}',
				array( 'queryPart' => serialize( $queryPart ) )
			);
		}

		// Actual text query
		list( $queryStringQueryString, $this->fuzzyQuery ) =
			$escaper->fixupWholeQueryString( implode( ' ', $escapedQuery ) );
		// Note that no escaping is required for near_match's match query.
		$nearMatchQuery = implode( ' ', $nearMatchQuery );
		if ( $queryStringQueryString !== '' ) {
			if ( preg_match( '/(?<!\\\\)[?*+~"!|-]|AND|OR|NOT/', $queryStringQueryString ) ) {
				$this->searchContext->setSearchContainedSyntax( true );
				// We're unlikey to make good suggestions for query string with special syntax in them....
				$showSuggestion = false;
			}
			$fields = array_merge(
				$this->buildFullTextSearchFields( 1, '.plain', true ),
				$this->buildFullTextSearchFields( $this->config->get( 'CirrusSearchStemmedWeight' ), '', true ) );
			$nearMatchFields = $this->buildFullTextSearchFields( $this->config->get( 'CirrusSearchNearMatchWeight' ),
				'.near_match', true );
			$this->query = $this->buildSearchTextQuery( $fields, $nearMatchFields,
				$queryStringQueryString, $nearMatchQuery );

			// The highlighter doesn't know about the weightinging from the all fields so we have to send
			// it a query without the all fields.  This swaps one in.
			if ( $this->config->getElement( 'CirrusSearchAllFields', 'use' ) ) {
				$nonAllFields = array_merge(
					$this->buildFullTextSearchFields( 1, '.plain', false ),
					$this->buildFullTextSearchFields( $this->config->get( 'CirrusSearchStemmedWeight' ), '', false ) );
				list( $nonAllQueryString, /*_*/ ) = $escaper->fixupWholeQueryString( implode( ' ', $nonAllQuery ) );
				$this->highlightQuery = $this->buildSearchTextQueryForFields( $nonAllFields, $nonAllQueryString, 1, false, true );
			} else {
				$nonAllFields = $fields;
			}

			// Only do a phrase match rescore if the query doesn't include any quotes and has a space.
			// Queries without spaces are either single term or have a phrase query generated.
			// Queries with the quote already contain a phrase query and we can't build phrase queries
			// out of phrase queries at this point.
			if ( $this->config->get( 'CirrusSearchPhraseRescoreBoost' ) > 1.0 &&
					$this->config->get( 'CirrusSearchPhraseRescoreWindowSize' ) &&
					!$this->searchContext->isSearchContainedSyntax() &&
					strpos( $queryStringQueryString, '"' ) === false &&
					strpos( $queryStringQueryString, ' ' ) !== false ) {

				$rescoreFields = $fields;
				if ( !$this->config->get( 'CirrusSearchAllFieldsForRescore' ) ) {
					$rescoreFields = $nonAllFields;
				}

				$this->rescore[] = array(
					'window_size' => $this->config->get( 'CirrusSearchPhraseRescoreWindowSize' ),
					'query' => array(
						'rescore_query' => $this->buildSearchTextQueryForFields( $rescoreFields,
							'"' . $queryStringQueryString . '"', $this->config->getElement( 'CirrusSearchPhraseSlop', 'boost' ), true ),
						'query_weight' => 1.0,
						'rescore_query_weight' => $this->config->get( 'CirrusSearchPhraseRescoreBoost' ),
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

			$result = $this->search( $searchType, $originalTerm );

			if ( !$result->isOK() && $this->isParseError( $result ) ) {
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
	 * Builds an or between many categories that the page could be in.
	 * @param string[] $categories categories to match
	 * @return \Elastica\Filter\Bool|null A null return value means all values are filtered
	 *  and an empty result set should be returned.
	 */
	public function matchPageCategories( $categories ) {
		$filter = new \Elastica\Filter\Bool();
		$ids = array();
		$names = array();
		foreach ( $categories as $category ) {
			if ( substr( $category, 0, 3 ) === 'id:' ) {
				$id = substr( $category, 3 );
				if ( ctype_digit( $id ) ) {
					$ids[] = $id;
				}
			} else {
				$names[] = $category;
			}
		}
		foreach ( Title::newFromIds( $ids ) as $title ) {
			$names[] = $title->getText();
		}
		if ( !$names ) {
			return null;
		}
		foreach( $names as $name ) {
			$filter->addShould( $this->matchPage( 'category.lowercase_keyword', $name ) );
		}
		return $filter;
	}

	/**
	 * Find articles that contain similar text to the provided title array.
	 * @param Title[] $titles array of titles of articles to search for
	 * @param int $options bitset of options:
	 *  MORE_LIKE_THESE_NONE
	 *  MORE_LIKE_THESE_ONLY_WIKIBASE - filter results to only those containing wikibase items
	 * @return Status<ResultSet>
	 */
	public function moreLikeTheseArticles( array $titles, $options = Searcher::MORE_LIKE_THESE_NONE ) {
		$pageIds = array();
		foreach ( $titles as $title ) {
			$pageIds[] = $title->getArticleID();
		}

		// If no fields has been set we return no results.
		// This can happen if the user override this setting with field names that
		// are not allowed in $this->config->get( 'CirrusSearchMoreLikeThisAllowedFields (see Hooks.php)
		if( !$this->config->get( 'CirrusSearchMoreLikeThisFields' ) ) {
			return Status::newGood( new SearchResultSet( true ) /* empty */ );
		}

		$this->searchContext->setSearchContainedSyntax( true );
		$moreLikeThisFields = $this->config->get( 'CirrusSearchMoreLikeThisFields' );
		$moreLikeThisUseFields = $this->config->get( 'CirrusSearchMoreLikeThisUseFields' );
		$this->query = new \Elastica\Query\MoreLikeThis();
		$this->query->setParams( $this->config->get( 'CirrusSearchMoreLikeThisConfig' ) );
		$this->query->setFields( $moreLikeThisFields );

		// The 'all' field cannot be retrieved from _source
		// We have to extract the text content before.
		if( in_array( 'all', $moreLikeThisFields ) ) {
			$moreLikeThisUseFields = false;
		}

		if ( !$moreLikeThisUseFields && $moreLikeThisFields != array( 'text' ) ) {
			// Run a first pass to extract the text field content because we want to compare it
			// against other fields.
			$text = array();
			$found = $this->get( $pageIds, array( 'text' ) );
			if ( !$found->isOk() ) {
				return $found;
			}
			$found = $found->getValue();
			if ( count( $found ) === 0 ) {
				// If none of the pages are in the index we can't find articles like them
				return Status::newGood( new SearchResultSet() /* empty */ );
			}
			foreach ( $found as $foundArticle ) {
				$text[] = $foundArticle->text;
			}
			$this->query->setLikeText( implode( ' ', $text ) );
		}

		// @todo: use setIds when T104560 is done
		$this->query->setParam( 'ids', $pageIds );

		if ( $options & Searcher::MORE_LIKE_THESE_ONLY_WIKIBASE ) {
			$this->filters[] = new \Elastica\Filter\Exists( 'wikibase_item' );
		}

		// Morelike is extremely costly, since terms can be completely random
		// highlight snippets are not great so it's worth running a match all query
		// to save cpu cycles
		$this->highlightQuery = new \Elastica\Query\MatchAll();
		return $this->search( 'more_like', implode( ', ', $titles ) );
	}

	/**
	 * Get the page with $id.  Note that the result is a status containing _all_ pages found.
	 * It is possible to find more then one page if the page is in multiple indexes.
	 * @param int[] $pageIds array of page ids
	 * @param string[]|true|false $sourceFiltering source filtering to apply
	 * @return Status containing pages found, containing an empty array if not found,
	 *    or an error if there was an error
	 */
	public function get( array $pageIds, $sourceFiltering ) {
		$indexType = $this->pickIndexTypeFromNamespaces();
		$searcher = $this;
		$indexBaseName = $this->indexBaseName;
		$conn = $this->connection;
		return Util::doPoolCounterWork(
			'CirrusSearch-Search',
			$this->user,
			function() use ( $searcher, $pageIds, $sourceFiltering, $indexType, $indexBaseName, $conn ) {
				try {
					$searcher->start( "get of {indexType}.{pageIds}", array(
						'indexType' => $indexType,
						'pageIds' => array_map( 'intval', $pageIds ),
					) );
					// Shard timeout not supported on get requests so we just use the client side timeout
					$conn->setTimeout( $this->config->getElement( 'CirrusSearchClientSideSearchTimeout', 'default' ) );
					$pageType = $conn->getPageType( $indexBaseName, $indexType );
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

	/**
	 * @param string $name
	 * @return Status
	 */
	public function findNamespace( $name ) {
		$searcher = $this;
		$indexBaseName = $this->indexBaseName;
		$conn = $this->connection;
		return Util::doPoolCounterWork(
			'CirrusSearch-NamespaceLookup',
			$this->user,
			function() use ( $searcher, $name, $indexBaseName, $conn ) {
				try {
					$searcher->start( "lookup namespace for {namespaceName}", array(
						'namespaceName' => $name,
						'query' => $name,
						'queryType' => 'namespace',
					) );
					$pageType = $conn->getNamespaceType( $indexBaseName );
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

	/**
	 * @param string $regex
	 * @param callable $callback
	 */
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

	/**
	 * @param array[] $query
	 * @param string $regex
	 * @param callable $callable
	 * @return array[]
	 */
	private static function replaceAllPartsOfQuery( array $query, $regex, $callable ) {
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

	/**
	 * @param string $queryPart
	 * @param string $regex
	 * @param callable $callable
	 * @return array[]
	 */
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
	 *
	 * @param string $type
	 * @param string $for
	 * @return Status results from the query transformed by the resultsType
	 */
	private function search( $type, $for ) {
		if ( $this->nonTextQueries ) {
			$bool = new \Elastica\Query\Bool();
			if ( $this->query !== null ) {
				$bool->addMust( $this->query );
			}
			foreach ( $this->nonTextQueries as $nonTextQuery ) {
				$bool->addMust( $nonTextQuery );
			}
			$this->query = $bool;
		}

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
		$namespaces = $this->getNamespaces();
		if ( $namespaces ) {
			$extraIndexes = $this->getAndFilterExtraIndexes();
			if ( $this->needNsFilter( $extraIndexes, $indexType ) ) {
				$this->filters[] = new \Elastica\Filter\Terms( 'namespace', $namespaces );
			}
		}

		// Wrap $this->query in a filtered query if there are any filters
		$unifiedFilter = Filters::unify( $this->filters, $this->notFilters );
		if ( $unifiedFilter !== null ) {
			$this->query = new \Elastica\Query\Filtered( $this->query, $unifiedFilter );
		}

		// Call installBoosts right after we're done munging the query to include filters
		// so any rescores installBoosts adds to the query are done against filtered results.
		$this->installBoosts( $type );

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
			if ( !empty( $this->nonTextHighlightQueries ) ) {
				// We have some phrase_prefix queries, so let's include them in the
				// generated highlight_query.
				$bool = new \Elastica\Query\Bool();
				if ( $this->highlightQuery ) {
					$bool->addShould( $this->highlightQuery );
				}
				foreach ( $this->nonTextHighlightQueries as $nonTextHighlightQuery ) {
					$bool->addShould( $nonTextHighlightQuery );
				}
				$this->highlightQuery = $bool;
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
			// Clear rescores if we aren't using relevance as the search sort because they aren't used.
			$this->rescore = array();
		}

		if ( $this->rescore ) {
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
		default:
			LoggerFactory::getInstance( 'CirrusSearch' )->warning(
				"Invalid sort type: {sort}",
				array( 'sort' => $this->sort )
			);
		}

		$queryOptions = array();
		if ( $this->config->get( 'CirrusSearchMoreAccurateScoringMode' ) ) {
			$queryOptions[ 'search_type' ] = 'dfs_query_then_fetch';
		}

		switch( $type ) {
		case 'regex':
			$poolCounterType = 'CirrusSearch-Regex';
			$queryOptions[ 'timeout' ] = $this->config->getElement( 'CirrusSearchSearchShardTimeout', 'regex' );
			break;
		case 'prefix':
			$poolCounterType = 'CirrusSearch-Prefix';
			$queryOptions[ 'timeout' ] = $this->config->getElement( 'CirrusSearchSearchShardTimeout', 'default' );
			break;
		default:
			$poolCounterType = 'CirrusSearch-Search';
			$queryOptions[ 'timeout' ] = $this->config->getElement( 'CirrusSearchSearchShardTimeout', 'default' );
		}
		$this->connection->setTimeout( $queryOptions[ 'timeout' ] );

		// Setup the search
		$pageType = $this->connection->getPageType( $this->indexBaseName, $indexType );
		$search = $pageType->createSearch( $query, $queryOptions );
		foreach ( $extraIndexes as $i ) {
			$search->addIndex( $i );
		}

		$description = "{queryType} search for '{query}'";
		$logContext = array(
			'queryType' => $type,
			'query' => $for,
			'limit' => $this->limit ?: null,
			// null means not requested, '' means not found. If found
			// parent::buildLogContext will replace the '' with an
			// actual suggestion.
			'suggestion' => $this->suggest ? '' : null,
		);

		if ( $this->returnQuery ) {
			return Status::newGood( array(
				'description' => $this->formatDescription( $description, $logContext ),
				'path' => $search->getPath(),
				'params' => $search->getOptions(),
				'query' => $query->toArray(),
				'options' => $queryOptions,
			) );
		}

		if ( $this->returnExplain && $this->returnResult ) {
			$query->setExplain( true );
		}

		// Perform the search
		$searcher = $this;
		$user = $this->user;
		$result = Util::doPoolCounterWork(
			$poolCounterType,
			$this->user,
			function() use ( $searcher, $search, $description, $logContext ) {
				try {
					$searcher->start( $description, $logContext );
					return $searcher->success( $search->search() );
				} catch ( \Elastica\Exception\ExceptionInterface $e ) {
					return $searcher->failure( $e );
				}
			},
			function( $error, $key, $userName ) use ( $type, $description, $user, $logContext ) {
				$forUserName = $userName ? "for {userName} " : '';
				LoggerFactory::getInstance( 'CirrusSearch' )->warning(
					"Pool error {$forUserName}on key {key} during $description:  {error}",
					$logContext + array(
						'userName' => $userName,
						'key' => 'key',
						'error' => $error
					)
				);

				if ( $error === 'pool-queuefull' ) {
					if ( strpos( $key, 'nowait:CirrusSearch:_per_user' ) === 0 ) {
						$loggedIn = $user->isLoggedIn() ? 'logged-in' : 'anonymous';
						return Status::newFatal( "cirrussearch-too-busy-for-you-{$loggedIn}-error" );
					}
					if ( $type === 'regex' ) {
						return Status::newFatal( 'cirrussearch-regex-too-busy-error' );
					}
					return Status::newFatal( 'cirrussearch-too-busy-error' );
				}
				return Status::newFatal( 'cirrussearch-backend-error' );
			});
		if ( $result->isOK() ) {
			$responseData = $result->getValue()->getResponse()->getData();

			if ( $this->returnResult ) {
				return Status::newGood( array(
						'description' => $this->formatDescription( $description, $logContext ),
						'path' => $search->getPath(),
						'result' => $responseData,
				) );
			}

			$result->setResult( true, $this->resultsType->transformElasticsearchResult( $this->suggestPrefixes,
				$this->suggestSuffixes, $result->getValue(), $this->searchContext->isSearchContainedSyntax() ) );
			if ( isset( $responseData['timed_out'] ) && $responseData[ 'timed_out' ] ) {
				LoggerFactory::getInstance( 'CirrusSearch' )->warning(
					"$description timed out and only returned partial results!",
					$logContext
				);
				if ( $result->getValue()->numRows() === 0 ) {
					return Status::newFatal( 'cirrussearch-backend-error' );
				} else {
					$result->warning( 'cirrussearch-timed-out' );
				}
			}
		}

		return $result;
	}

	/**
	 * @return int[]|null
	 */
	public function getNamespaces() {
		return $this->searchContext->getNamespaces();
	}

	/**
	 * @param string[] $extraIndexes
	 * @param string $indexType
	 * @return boolean
	 */
	private function needNsFilter( array $extraIndexes, $indexType ) {
		if ( $extraIndexes ) {
			// We're reaching into another wiki's indexes and we don't know what is there so be defensive.
			return true;
		}
		$nsCount = count( $this->getNamespaces() );
		$validNsCount = count( MWNamespace::getValidNamespaces() );
		if ( $nsCount === $validNsCount ) {
			// We're only on our wiki and we're searching _everything_.
			return false;
		}
		if ( !$indexType ) {
			// We're searching less than everything but we're going across indexes.  Back to the defensive.
			return true;
		}
		$namespacesInIndexType = $this->connection->namespacesInIndexType( $indexType );
		return $nsCount !== $namespacesInIndexType;
	}

	/**
	 * @param string[] $fields
	 * @param string[] $nearMatchFields
	 * @param string $queryString
	 * @param string $nearMatchQuery
	 * @return \Elastica\Query\Simple|\Elastica\Query\Bool
	 */
	private function buildSearchTextQuery( array $fields, array $nearMatchFields, $queryString, $nearMatchQuery ) {
		$queryForMostFields = $this->buildSearchTextQueryForFields( $fields, $queryString,
				$this->config->getElement( 'CirrusSearchPhraseSlop', 'default' ), false );
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

	/**
	 * @todo: refactor as we may want to implement many different way to build
	 *        the main query.
	 * @param string[] $fields
	 * @param string $queryString
	 * @param int $phraseSlop
	 * @param boolean $isRescore
	 * @return \Elastica\Query\Simple
	 */
	private function buildSearchTextQueryForFields( array $fields, $queryString, $phraseSlop, $isRescore, $forHighlight = false ) {
		$searchTextQueryBuilder = $this->searchContext->searchTextQueryBuilder( $queryString );
		if ( $isRescore ) {
			return $searchTextQueryBuilder->buildRescoreQuery( $fields, $queryString, $phraseSlop );
		} else if( $forHighlight ) {
			return $searchTextQueryBuilder->buildHighlightQuery( $fields, $queryString, $phraseSlop );
		} else {
			return $searchTextQueryBuilder->buildMainQuery( $fields, $queryString, $phraseSlop );
		}
	}

	/**
	 * Build suggest config for $field.
	 * @param string $field field to suggest against
	 * @return array[] array of Elastica configuration
	 */
	private function buildSuggestConfig( $field ) {
		// check deprecated settings
		$suggestSettings = $this->config->get( 'CirrusSearchPhraseSuggestSettings' );
		$maxErrors = $this->config->get( 'CirrusSearchPhraseSuggestMaxErrors' );
		if ( isset( $maxErrors ) ) {
			$suggestSettings['max_errors'] = $maxErrors;
		}
		$confidence = $this->config->get( 'CirrusSearchPhraseSuggestMaxErrors' );
		if ( isset( $confidence ) ) {
			$suggestSettings['confidence'] = $confidence;
		}

		$settings = array(
			'phrase' => array(
				'field' => $field,
				'size' => 1,
				'max_errors' => $suggestSettings['max_errors'],
				'confidence' => $suggestSettings['confidence'],
				'real_word_error_likelihood' => $suggestSettings['real_word_error_likelihood'],
				'direct_generator' => array(
					array(
						'field' => $field,
						'suggest_mode' => $suggestSettings['mode'],
						'max_term_freq' => $suggestSettings['max_term_freq'],
						'min_doc_freq' => $suggestSettings['min_doc_freq'],
						'prefix_length' => $suggestSettings['prefix_length'],
					),
				),
				'highlight' => array(
					'pre_tag' => self::SUGGESTION_HIGHLIGHT_PRE,
					'post_tag' => self::SUGGESTION_HIGHLIGHT_POST,
				),
			),
		);
		if ( !empty( $suggestSettings['collate'] ) ) {
			$collateFields = array('title.plain', 'redirect.title.plain');
			if ( $this->config->get( 'CirrusSearchPhraseSuggestUseText' )  ) {
				$collateFields[] = 'text.plain';
			}
			$settings['phrase']['collate'] = array(
				'query' => array (
					'multi_match' => array(
						'query' => '{{suggestion}}',
						'operator' => 'or',
						'minimum_should_match' => $suggestSettings['collate_minimum_should_match'],
						'type' => 'cross_fields',
						'fields' => $collateFields
					)
				)
			);
		}
		if( isset( $suggestSettings['smoothing_model'] ) ) {
			$settings['phrase']['smoothing'] = $suggestSettings['smoothing_model'];
		}
		return $settings;
	}

	/**
	 * @param string $term
	 * @param boolean $allFieldAllowed
	 * @return string
	 */
	public function switchSearchToExact( $term, $allFieldAllowed ) {
		$exact = join( ' OR ', $this->buildFullTextSearchFields( 1, ".plain:$term", $allFieldAllowed ) );
		return "($exact)";
	}

	/**
	 * Expand wildcard queries to the all.plain and title.plain fields if
	 * wgCirrusSearchAllFields[ 'use' ] is set to true. Fallback to all
	 * the possible fields otherwize. This prevents applying and compiling
	 * costly wildcard queries too many times.
	 * @param string $term
	 * @return string
	 */
	public function switchSearchToExactForWildcards( $term ) {
		// Try to limit the expansion of wildcards to all the subfields
		// We still need to add title.plain with a high boost otherwise
		// match in titles be poorly scored (actually it breaks some tests).
		if( $this->config->getElement( 'CirrusSearchAllFields', 'use' ) ) {
			$titleWeight = $this->config->getElement( 'CirrusSearchWeights', 'title' );
			$fields = array();
			$fields[] = "title.plain:$term^${titleWeight}";
			$fields[] = "all.plain:$term";
			$exact = join( ' OR ', $fields );
			return "($exact)";
		} else {
			return $this->switchSearchToExact( $term, false );
		}
	}

	/**
	 * Build fields searched by full text search.
	 * @param float $weight weight to multiply by all fields
	 * @param string $fieldSuffix suffux to add to field names
	 * @param boolean $allFieldAllowed can we use the all field?  False for
	 *    collecting phrases for the highlighter.
	 * @return string[] array of fields to query
	 */
	public function buildFullTextSearchFields( $weight, $fieldSuffix, $allFieldAllowed ) {
		if ( $this->config->getElement( 'CirrusSearchAllFields', 'use' ) && $allFieldAllowed ) {
			if ( $fieldSuffix === '.near_match' ) {
				// The near match fields can't shard a root field because field fields nead it -
				// thus no suffix all.
				return array( "all_near_match^${weight}" );
			}
			return array( "all${fieldSuffix}^${weight}" );
		}

		$fields = array();
		$searchWeights =  $this->config->get( 'CirrusSearchWeights' );
		// Only title and redirect support near_match so skip it for everything else
		$titleWeight = $weight * $searchWeights[ 'title' ];
		$redirectWeight = $weight * $searchWeights[ 'redirect' ];
		if ( $fieldSuffix === '.near_match' ) {
			$fields[] = "title${fieldSuffix}^${titleWeight}";
			$fields[] = "redirect.title${fieldSuffix}^${redirectWeight}";
			return $fields;
		}
		$fields[] = "title${fieldSuffix}^${titleWeight}";
		$fields[] = "redirect.title${fieldSuffix}^${redirectWeight}";
		$categoryWeight = $weight * $searchWeights[ 'category' ];
		$headingWeight = $weight * $searchWeights[ 'heading' ];
		$openingTextWeight = $weight * $searchWeights[ 'opening_text' ];
		$textWeight = $weight * $searchWeights[ 'text' ];
		$auxiliaryTextWeight = $weight * $searchWeights[ 'auxiliary_text' ];
		$fields[] = "category${fieldSuffix}^${categoryWeight}";
		$fields[] = "heading${fieldSuffix}^${headingWeight}";
		$fields[] = "opening_text${fieldSuffix}^${openingTextWeight}";
		$fields[] = "text${fieldSuffix}^${textWeight}";
		$fields[] = "auxiliary_text${fieldSuffix}^${auxiliaryTextWeight}";
		$namespaces = $this->getNamespaces();
		if ( !$namespaces || in_array( NS_FILE, $namespaces ) ) {
			$fileTextWeight = $weight * $searchWeights[ 'file_text' ];
			$fields[] = "file_text${fieldSuffix}^${fileTextWeight}";
		}
		return $fields;
	}

	/**
	 * Pick the index type to search based on the list of namespaces to search.
	 * @return string|false either an index type or false to use all index types
	 */
	private function pickIndexTypeFromNamespaces() {
		$namespaces = $this->getNamespaces();
		if ( !$namespaces ) {
			return false; // False selects all index types
		}

		$indexTypes = array();
		foreach ( $namespaces as $namespace ) {
			$indexTypes[] =
				$this->connection->getIndexSuffixForNamespace( $namespace );
		}
		$indexTypes = array_unique( $indexTypes );
		return count( $indexTypes ) > 1 ? false : $indexTypes[0];
	}

	/**
	 * Retrieve the extra indexes for our searchable namespaces, if any
	 * exist. If they do exist, also add our wiki to our notFilters so
	 * we can filter out duplicates properly.
	 *
	 * @return string[]
	 */
	protected function getAndFilterExtraIndexes() {
		if ( $this->limitSearchToLocalWiki ) {
			return array();
		}
		$extraIndexes = OtherIndexes::getExtraIndexesForNamespaces( $this->getNamespaces() );
		if ( $extraIndexes ) {
			$this->notFilters[] = new \Elastica\Filter\Term(
				array( 'local_sites_with_dupe' => $this->indexBaseName ) );
		}
		return $extraIndexes;
	}

	/**
	 * If there is any boosting to be done munge the the current query to get it right.
	 */
	private function installBoosts( $type ) {
		if ( $this->sort !== 'relevance' ) {
			// Boosts are irrelevant if you aren't sorting by, well, relevance
			return;
		}

		$profile = $this->config->get( 'CirrusSearchRescoreProfile' );
		if ( $type === 'prefix' ) {
			$profile = $this->config->get( 'CirrusSearchPrefixSearchRescoreProfile' );
		} else if( $type == 'more_like' ) {
			$profile = $this->config->get( 'CirrusSearchMoreLikeRescoreProfile' );
		}
		$builder = new RescoreBuilder( $this->searchContext, $profile );
		$this->rescore = array_merge( $this->rescore, $builder->build() );
	}


	/**
	 * @param string $search
	 * @throws UsageException
	 */
	private function checkTitleSearchRequestLength( $search ) {
		$requestLength = mb_strlen( $search );
		if ( $requestLength > self::MAX_TITLE_SEARCH ) {
			throw new UsageException( 'Prefix search request was longer than the maximum allowed length.' .
				" ($requestLength > " . self::MAX_TITLE_SEARCH . ')', 'request_too_long', 400 );
		}
	}

	/**
	 * @param string $search
	 * @return Status
	 */
	private function checkTextSearchRequestLength( $search ) {
		$requestLength = mb_strlen( $search );
		if (
			$requestLength > self::MAX_TEXT_SEARCH &&
			// allow category intersections longer than the maximum
			strpos( $search, 'incategory:' ) === false
		) {
			return Status::newFatal( 'cirrussearch-query-too-long', $this->language->formatNum( $requestLength ), $this->language->formatNum( self::MAX_TEXT_SEARCH ) );
		}
		return Status::newGood();
	}

	/**
	 * Attempt to suck a leading namespace followed by a colon from the query string.  Reaches out to Elasticsearch to
	 * perform normalized lookup against the namespaces.  Should be fast but for the network hop.
	 *
	 * @param string &$query
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
		if ( !$foundNamespace ) {
			return;
		}
		$foundNamespace = $foundNamespace[ 0 ];
		$query = substr( $query, $colon + 1 );
		$this->searchContext->setNamespaces( array( $foundNamespace->getId() ) );
	}

	/**
	 * Perform a quick and dirty replacement for $this->description
	 * when it's not going through monolog. It replaces {foo} with
	 * the value from $context['foo'].
	 *
	 * @param string $input String to perform replacement on
	 * @param array $context patterns and their replacements
	 * @return string $input with replacements from $context performed
	 */
	private function formatDescription( $input, $context ) {
		$pairs = array();
		foreach ( $context as $key => $value ) {
			$pairs['{' . $key . '}'] = $value;
		}
		return strtr( $input, $pairs );
	}

	/**
	 * Try to detect language via Accept-Language header. Takes the
	 * first accept-language that is not the current content language.
	 * @return string|null Language name or null
	 */
	public static function detectLanguageViaAcceptLang() {
		$acceptLang = array_keys( $GLOBALS['wgRequest']->getAcceptLang() );
		$currentShortLang = $GLOBALS['wgContLang']->getCode();
		foreach ( $acceptLang as $lang ) {
			list( $shortLang ) = explode( "-", $lang, 2 );
			if ( $shortLang !== $currentShortLang ) {
				// return only the primary-tag, stripping the subtag
				// so the language to wiki map doesn't need all
				// possible combinations (quite a few).
				return $shortLang;
			}
		}
		return null;
	}

	/**
	 * Try to detect language using langdetect plugin
	 * See: https://github.com/jprante/elasticsearch-langdetect
	 * @param CirrusSearch $cirrus SearchEngine implementation for cirrus
	 * @param string $text
	 * @return string|null Language name or null
	 */
	public static function detectLanguageViaES( CirrusSearch $cirrus, $text ) {
		$client = $cirrus->getConnection()->getClient();
		try {
			$response = $client->request( "_langdetect", Request::POST, $text );
		} catch ( ResponseException $e ) {
			// This happens when language detection is not configured
			LoggerFactory::getInstance( 'CirrusSearch' )->warning(
				"Could not connect to language detector: {exception}",
				array( "exception" => $e->getMessage() )
			);
			return null;
		}
		if ( $response->isOk() ) {
			$value = $response->getData();
			if ( $value && !empty( $value['languages'] ) ) {
				$langs = $value['languages'];
				if ( count( $langs ) == 1 ) {
					// TODO: add minimal threshold
					return $langs[0]['language'];
				}
				// FIXME: here I'm just winging it, should be something
				// that makes sense for multiple languages
				if ( count( $langs ) == 2) {
					if( $langs[0]['probability'] > 2*$langs[1]['probability'] ) {
						return $langs[0]['language'];
					}
				}
			}
		}
		return null;
	}

	/**
	 * @return SearchContext
	 */
	public function getSearchContext() {
		return $this->searchContext;
	}

}
