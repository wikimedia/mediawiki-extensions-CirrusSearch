<?php

namespace CirrusSearch;

use Elastica;
use CirrusSearch;
use CirrusSearch\Extra\Query\SourceRegex;
use CirrusSearch\Search\Escaper;
use CirrusSearch\Search\Filters;
use CirrusSearch\Search\FullTextResultsType;
use CirrusSearch\Search\ResultsType;
use CirrusSearch\Search\RescoreBuilder;
use CirrusSearch\Search\SearchContext;
use GeoData\Coord;
use Language;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MWNamespace;
use ObjectCache;
use SearchResultSet;
use Status;
use Title;
use UsageException;
use User;

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
	 * Maximum offset + limit depth allowed. As in the deepest possible result
	 * to return. Too deep will cause very slow queries. 10,000 feels plenty
	 * deep. This should be <= index.max_result_window in elasticsearch.
	 */
	const MAX_OFFSET_LIMIT = 10000;

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
	 * @var string index base name to use
	 */
	private $indexBaseName;

	/**
	 * @var Escaper escapes queries
	 */
	private $escaper;

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
	 * Search environment configuration
	 * @var SearchConfig
	 */
	protected $config;

	/**
	 * @var SearchContext
	 */
	protected $searchContext;

	/**
	 * Constructor
	 * @param Connection $conn
	 * @param int $offset Offset the results by this much
	 * @param int $limit Limit the results to this many
	 * @param SearchConfig|null $config Configuration settings
	 * @param int[]|null $namespaces Array of namespace numbers to search or null to search all namespaces.
	 * @param User|null $user user for which this search is being performed.  Attached to slow request logs.
	 * @param string|boolean $index Base name for index to search from, defaults to $wgCirrusSearchIndexBaseName
	 */
	public function __construct( Connection $conn, $offset, $limit, SearchConfig $config = null, array $namespaces = null,
		User $user = null, $index = false ) {

		if ( is_null( $config ) ) {
			// @todo connection has an embedded config ... reuse that? somehow should
			// at least ensure they are the same.
			$config = MediaWikiServices::getInstance()
				->getConfigFactory()
				->makeConfig( 'CirrusSearch' );
		}

		parent::__construct( $conn, $user, $config->get( 'CirrusSearchSlowSearch' ) );
		$this->config = $config;
		$this->offset = $offset;
		if ( $offset + $limit > self::MAX_OFFSET_LIMIT ) {
			$this->limit = self::MAX_OFFSET_LIMIT - $offset;
		} else {
			$this->limit = $limit;
		}
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
		$this->searchContext->setLimitSearchToLocalWiki( $limitSearchToLocalWiki );
	}

	/**
	 * Perform a "near match" title search which is pretty much a prefix match without the prefixes.
	 * @param string $search text by which to search
	 * @return Status status containing results defined by resultsType on success
	 */
	public function nearMatchTitleSearch( $search ) {
		$this->checkTitleSearchRequestLength( $search );

		// Elasticsearch seems to have trouble extracting the proper terms to highlight
		// from the default query we make so we feed it exactly the right query to highlight.
		$highlightQuery = new \Elastica\Query\MultiMatch();
		$highlightQuery->setQuery( $search );
		$highlightQuery->setFields( array(
			'title.near_match', 'redirect.title.near_match',
			'title.near_match_asciifolding', 'redirect.title.near_match_asciifolding',
		) );
		if ( $this->config->getElement( 'CirrusSearchAllFields', 'use' ) ) {
			// Instead of using the highlight query we need to make one like it that uses the all_near_match field.
			$allQuery = new \Elastica\Query\MultiMatch();
			$allQuery->setQuery( $search );
			$allQuery->setFields( array( 'all_near_match', 'all_near_match.asciifolding' ) );
			$this->searchContext->addFilter( $allQuery );
		} else {
			$this->searchContext->addFilter( $highlightQuery );
		}
		$this->searchContext->setHighlightQuery( $highlightQuery );
		$this->searchContext->setSearchType( 'near_match' );

		return $this->search( $search );
	}

	/**
	 * Perform a prefix search.
	 * @param string $search text by which to search
	 * @return Status status containing results defined by resultsType on success
	 */
	public function prefixSearch( $search ) {
		$this->checkTitleSearchRequestLength( $search );

		$this->searchContext->setSearchType( 'prefix' );
		if ( strlen( $search ) > 0 ) {
			if ( $this->config->get( 'CirrusSearchPrefixSearchStartsWithAnyWord' ) ) {
				$match = new \Elastica\Query\Match();
				$match->setField( 'title.word_prefix', array(
					'query' => $search,
					'analyzer' => 'plain',
					'operator' => 'and',
				) );
				$this->searchContext->addFilter( $match );
			} else {
				// Elasticsearch seems to have trouble extracting the proper terms to highlight
				// from the default query we make so we feed it exactly the right query to highlight.
				$query = new \Elastica\Query\MultiMatch();
				$query->setQuery( $search );
				$weights = $this->config->get( 'CirrusSearchPrefixWeights' );
				$query->setFields( array(
					'title.prefix^' . $weights[ 'title' ],
					'redirect.title.prefix^' . $weights[ 'redirect' ],
					'title.prefix_asciifolding^' . $weights[ 'title_asciifolding' ],
					'redirect.title.prefix_asciifolding^' . $weights[ 'redirect_asciifolding' ],
				) );
				$this->searchContext->setMainQuery( $query );
			}
		}

		/** @suppress PhanDeprecatedFunction */
		$this->searchContext->setBoostLinks( true );

		return $this->search( $search );
	}

	/**
	 * @param string $suggestPrefix prefix to be prepended to suggestions
	 */
	public function addSuggestPrefix( $suggestPrefix ) {
		$this->searchContext->addSuggestPrefix( $suggestPrefix );
	}

	/**
	 * Search articles with provided term.
	 * @param string $term term to search
	 * @param boolean $showSuggestion should this search suggest alternative searches that might be better?
	 * @return Status status containing results defined by resultsType on success
	 */
	public function searchText( $term, $showSuggestion ) {
		$checkLengthStatus = $this->checkTextSearchRequestLength( $term );
		if ( !$checkLengthStatus->isOK() ) {
			return $checkLengthStatus;
		}

		// Transform Mediawiki specific syntax to filters and extra (pre-escaped) query string
		$originalTerm = $term;
		$this->searchContext->setSearchType( 'full_text' );
		// Handle title prefix notation
		$prefixPos = strpos( $term, 'prefix:' );
		if ( $prefixPos !== false ) {
			$value = substr( $term, 7 + $prefixPos );
			$value = trim( $value, '"' ); // Trim quotes in case the user wanted to quote the prefix
			if ( strlen( $value ) > 0 ) {
				$this->searchContext->addSyntaxUsed( "prefix" );
				$term = substr( $term, 0, max( 0, $prefixPos - 1 ) );
				$this->searchContext->addSuggestSuffix( ' prefix:' . $value );
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
					$this->searchContext->addFilter( $prefixQuery );
				}
			}
		}

		// Matches "prefer-recent:" and then an optional floating point number <= 1 but >= 0 (decay
		// portion) and then an optional comma followed by another floating point number >= 0 (half life)
		$term = $this->extractSpecialSyntaxFromTerm(
			$term,
			'/prefer-recent:(1|0?(?:\.\d+)?)?(?:,(\d*\.?\d+))? ?/',
			function ( $matches ) {
				$preferRecentHalfLife = $this->config->get( 'CirrusSearchPreferRecentDefaultHalfLife' );
				$unspecifiedDecayPortion = $this->config->get( 'CirrusSearchPreferRecentUnspecifiedDecayPortion' );
				if ( isset( $matches[ 1 ] ) && strlen( $matches[ 1 ] ) ) {
					$preferRecentDecayPortion = floatval( $matches[ 1 ] );
				} else {
					$preferRecentDecayPortion = $unspecifiedDecayPortion;
				}
				if ( isset( $matches[ 2 ] ) ) {
					$preferRecentHalfLife = floatval( $matches[ 2 ] );
				}
				$this->searchContext->setPreferRecentOptions( $preferRecentDecayPortion, $preferRecentHalfLife );
				$this->searchContext->addSyntaxUsed( 'prefer-recent' );
				return '';
			}
		);

		$term = $this->extractSpecialSyntaxFromTerm(
			$term,
			'/^\s*local:/',
			function () {
				$this->searchContext->setLimitSearchToLocalWiki( true );
				$this->searchContext->addSyntaxUsed( 'local' );
				return '';
			}
		);

		// Handle other filters
		$term = $this->extractSpecialSyntaxFromTerm(
			$term,
			'/(?<not>-)?insource:\/(?<pattern>(?:[^\\\\\/]|\\\\.)+)\/(?<insensitive>i)? ?/',
			function ( $matches ) {

				if ( !$this->config->get( 'CirrusSearchEnableRegex' ) ) {
					return;
				}

				$this->searchContext->addSyntaxUsed( 'regex' );
				$this->searchContext->setSearchType( 'regex' );
				$insensitive = !empty( $matches[ 'insensitive' ] );

				if ( !empty( $matches[ 'not' ] ) ) {
					$negated = true;
				} else {
					$negated = false;
					$this->searchContext->addHighlightSource( array(
						'pattern' => $matches[ 'pattern' ],
						'locale' => $this->config->get( 'LanguageCode' ),
						'insensitive' => $insensitive,
					) );
				}
				$regex = $this->config->getElement( 'CirrusSearchWikimediaExtraPlugin', 'regex' );
				if ( $regex && in_array( 'use', $regex ) ) {
					$filter = new SourceRegex( $matches[ 'pattern' ], 'source_text', 'source_text.trigram' );
					if ( isset( $regex[ 'max_inspect' ] ) ) {
						$filter->setMaxInspect( $regex[ 'max_inspect' ] );
					} else {
						$filter->setMaxInspect( 10000 );
					}
					$filter->setMaxDeterminizedStates( $this->config->get( 'CirrusSearchRegexMaxDeterminizedStates' ) );
					if ( isset( $regex['max_ngrams_extracted'] ) ) {
						$filter->setMaxNgramsExtracted( $regex['max_ngrams_extracted'] );
					}
					if ( isset( $regex['max_ngram_clauses'] ) && is_numeric( $regex['max_ngram_clauses'] ) ) {
						$filter->setMaxNgramClauses( (int) $regex['max_ngram_clauses'] );
					}
					$filter->setCaseSensitive( !$insensitive );
					$filter->setLocale( $this->config->get( 'LanguageCode' ) );
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
					$filter = new \Elastica\Query\Script( new \Elastica\Script\Script(
						$script,
						array(
							'pattern' => '.*(' . $matches[ 'pattern' ] . ').*',
							'insensitive' => $insensitive,
							'language' => $this->config->get( 'LanguageCode' ),
							// These null here creates a slot in which the script will shove
							// an automaton while executing.
							'automaton' => null,
							'locale' => null,
						),
						'groovy'
					) );
				}

				if ( $negated ) {
					$this->searchContext->addNotFilter( $filter );
				} else {
					$this->searchContext->addFilter( $filter );
				}
			}
		);
		// Match filters that look like foobar:thing or foobar:"thing thing"
		// The {7,16} keeps this from having horrible performance on big strings
		$term = $this->extractSpecialSyntaxFromTerm(
			$term,
			'/(?<key>[a-z\\-]{7,16}):\s*(?<value>"(?<quoted>(?:[^"]|(?<=\\\)")+)"|(?<unquoted>\S+)) ?/',
			function ( $matches ) {
				$key = $matches['key'];
				$quotedValue = $matches['value'];
				$value = $matches['quoted'] !== ''
					? str_replace( '\"', '"', $matches['quoted'] )
					: $matches['unquoted'];
				if ( $key[ 0 ] === '-' ) {
					$negated = true;
					$key = substr( $key, 1 );
				} else {
					$negated = false;
				}
				$keepText = false;
				$filter = null;
				switch ( $key ) {
					case 'nearcoord':
						list( $coord, $radius ) = Util::parseGeoNearby( $value );
						if ( $coord ) {
							$this->searchContext->setSearchType( 'geo_' . $this->searchContext->getSearchType() );
							$filter = Filters::geo( $coord, $radius );
							$this->searchContext->addSyntaxUsed( $key );
						}
						break;
					case 'boost-nearcoord':
						// @todo Do we need a slightly different syntax for user specified weights?
						list( $coord, $radius ) = Util::parseGeoNearby( $value );
						if ( $coord ) {
							$this->searchContext->setSearchType( 'geo_' . $this->searchContext->getSearchType() );
							$this->getSearchContext()
								->addGeoBoost( $coord, $radius, $negated ? 0.1 : 1 );
							$this->searchContext->addSyntaxUsed( $key );
						}
						break;
					case 'neartitle':
						list( $coord, $radius, $exclude ) = Util::parseGeoNearbyTitle( $value );
						if ( $coord ) {
							$this->searchContext->setSearchType( 'geo_' . $this->searchContext->getSearchType() );
							$filter = Filters::geo( $coord, $radius, $exclude );
							$this->searchContext->addSyntaxUsed( $key );
						}
						break;
					case 'boost-neartitle':
						list( $coord, $radius, $exclude ) = Util::parseGeoNearbyTitle( $value );
						if ( $coord ) {
							$this->searchContext->setSearchType( 'geo_' . $this->searchContext->getSearchType() );
							$this->getSearchContext()
								->addGeoBoost( $coord, $radius, $negated ? 0.1 : 1 );
							$this->searchContext->addSyntaxUsed( $key );
						}
						break;
					case 'boost-templates':
						$boostTemplates = Util::parseBoostTemplates( $value );
						$this->getSearchContext()->setBoostTemplatesFromQuery( $boostTemplates );
						$this->searchContext->addSyntaxUsed( $key );
						break;
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
						$filter = $this->matchPage( 'template', $value );
						$this->searchContext->addSyntaxUsed( $key );
						break;
					case 'linksto':
						$filter = $this->matchPage( 'outgoing_link', $value, true );
						$this->searchContext->addSyntaxUsed( $key );
						break;
					case 'incategory':
						$categories = array_slice( explode( '|', $value ), 0, $this->config->get( 'CirrusSearchMaxIncategoryOptions' ) );
						$filter = $this->matchPageCategories( $categories );
						if ( $filter === null ) {
							$this->searchContext->setResultsPossible( false );
						}
						$this->searchContext->addSyntaxUsed( $key );
						break;
					case 'insource':
						$filter = Filters::insource( $this->escaper, $this->searchContext, $quotedValue );
						$this->searchContext->addSyntaxUsed( $key );
						$this->searchContext->addHighlightSource( array( 'query' => $filter ) );
						break;
					case 'intitle':
						$filter = Filters::intitle( $this->escaper, $this->searchContext, $quotedValue );
						$this->searchContext->addSyntaxUsed( $key );
						$keepText = !$negated;
						break;
					default:
						return $matches[0];
				}

				if ( $filter ) {
					if ( $negated ) {
						$this->searchContext->addNotFilter( $filter );
					} else {
						$this->searchContext->addFilter( $filter );
					}
				}

				return $keepText ? "$quotedValue " : '';
			}
		);

		if ( !$this->searchContext->areResultsPossible() ) {
			return Status::newGood( new SearchResultSet( true ) );
		}

		$term = $this->escaper->escapeQuotes( $term );
		$term = trim( $term );

		// Match quoted phrases including those containing escaped quotes
		// Those phrases can optionally be followed by ~ then a number (this is the phrase slop)
		// That can optionally be followed by a ~ (this matches stemmed words in phrases)
		// The following all match: "a", "a boat", "a\"boat", "a boat"~, "a boat"~9, "a boat"~9~, -"a boat", -"a boat"~9~
		$slop = $this->config->get('CirrusSearchPhraseSlop');
		$query = self::replacePartsOfQuery( $term, '/(?<![\]])(?<negate>-|!)?(?<main>"((?:[^"]|(?<=\\\)")+)"(?<slop>~\d+)?)(?<fuzzy>~)?/',
			function ( $matches ) use ( $slop ) {
				$negate = $matches[ 'negate' ][ 0 ] ? 'NOT ' : '';
				$main = $this->escaper->fixupQueryStringPart( $matches[ 'main' ][ 0 ] );

				if ( !$negate && !isset( $matches[ 'fuzzy' ] ) && !isset( $matches[ 'slop' ] ) &&
						 preg_match( '/^"([^"*]+)[*]"/', $main, $matches ) ) {
					$phraseMatch = new Elastica\Query\Match( );
					$phraseMatch->setFieldQuery( "all.plain", $matches[1] );
					$phraseMatch->setFieldType( "all.plain", "phrase_prefix" );
					$this->searchContext->addNonTextQuery( $phraseMatch );

					$phraseHighlightMatch = new Elastica\Query\QueryString( );
					$phraseHighlightMatch->setQuery( $matches[1] . '*' );
					$phraseHighlightMatch->setFields( array( 'all.plain' ) );
					$this->searchContext->addNonTextHighlightQuery( $phraseHighlightMatch );

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
						'escaped' => $negate . $this->switchSearchToExact( $main, true ),
						'nonAll' => $negate . $this->switchSearchToExact( $main, false ),
					);
				}
				return array( 'escaped' => $negate . $main );
			} );
		// Find prefix matches and force them to only match against the plain analyzed fields.  This
		// prevents prefix matches from getting confused by stemming.  Users really don't expect stemming
		// in prefix queries.
		$query = self::replaceAllPartsOfQuery( $query, '/\w+\*(?:\w*\*?)*/u',
			function ( $matches ) {
				$term = $this->escaper->fixupQueryStringPart( $matches[ 0 ][ 0 ] );
				return array(
					'escaped' => $this->switchSearchToExactForWildcards( $term ),
					'nonAll' => $this->switchSearchToExactForWildcards( $term )
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
		list( $queryStringQueryString, $isFuzzy ) =
			$this->escaper->fixupWholeQueryString( implode( ' ', $escapedQuery ) );
		$this->searchContext->setFuzzyQuery( $isFuzzy );
		// Note that no escaping is required for near_match's match query.
		$nearMatchQuery = implode( ' ', $nearMatchQuery );
		if ( $queryStringQueryString === '' ) {
			$result = $this->search( $originalTerm );
			// No need to check for a parse error here because we don't actually create a query for
			// Elasticsearch to parse
		} else {
			if ( preg_match( '/(?<!\\\\)[?*+~"!|-]|AND|OR|NOT/', $queryStringQueryString ) ) {
				$this->searchContext->addSyntaxUsed( 'query_string' );
				// We're unlikely to make good suggestions for query string with special syntax in them....
				$showSuggestion = false;
			}
			$fields = array_merge(
				$this->buildFullTextSearchFields( 1, '.plain', true ),
				$this->buildFullTextSearchFields( $this->config->get( 'CirrusSearchStemmedWeight' ), '', true ) );
			$nearMatchFields = $this->buildFullTextSearchFields( $this->config->get( 'CirrusSearchNearMatchWeight' ),
				'.near_match', true );

			$this->searchContext->setMainQuery( $this->buildSearchTextQuery(
				$fields,
				$nearMatchFields,
				$queryStringQueryString,
				$nearMatchQuery
			) );

			// The highlighter doesn't know about the weighting from the all fields so we have to send
			// it a query without the all fields.  This swaps one in.
			if ( $this->config->getElement( 'CirrusSearchAllFields', 'use' ) ) {
				$nonAllFields = array_merge(
					$this->buildFullTextSearchFields( 1, '.plain', false ),
					$this->buildFullTextSearchFields( $this->config->get( 'CirrusSearchStemmedWeight' ), '', false ) );
				list( $nonAllQueryString, /*_*/ ) = $this->escaper->fixupWholeQueryString( implode( ' ', $nonAllQuery ) );
				$this->searchContext->setHighlightQuery(
					$this->buildSearchTextQueryForFields( $nonAllFields, $nonAllQueryString, 1, false, true )
				);
			} else {
				$nonAllFields = $fields;
			}

			// Only do a phrase match rescore if the query doesn't include any quotes and has a space.
			// Queries without spaces are either single term or have a phrase query generated.
			// Queries with the quote already contain a phrase query and we can't build phrase queries
			// out of phrase queries at this point.
			if ( $this->config->get( 'CirrusSearchPhraseRescoreBoost' ) > 0.0 &&
					$this->config->get( 'CirrusSearchPhraseRescoreWindowSize' ) &&
					!$this->searchContext->isSyntaxUsed() &&
					strpos( $queryStringQueryString, '"' ) === false &&
					strpos( $queryStringQueryString, ' ' ) !== false ) {

				$rescoreFields = $fields;
				if ( !$this->config->get( 'CirrusSearchAllFieldsForRescore' ) ) {
					$rescoreFields = $nonAllFields;
				}

				$this->searchContext->addRescore( array(
					'window_size' => $this->config->get( 'CirrusSearchPhraseRescoreWindowSize' ),
					'query' => array(
						'rescore_query' => $this->buildSearchTextQueryForFields( $rescoreFields,
							'"' . $queryStringQueryString . '"', $this->config->getElement( 'CirrusSearchPhraseSlop', 'boost' ), true ),
						'query_weight' => 1.0,
						'rescore_query_weight' => $this->config->get( 'CirrusSearchPhraseRescoreBoost' ),
					)
				) );
			}

			$showSuggestion = $showSuggestion && ($this->offset == 0);

			if ( $showSuggestion ) {
				$this->searchContext->setSuggest( array(
					'text' => $term,
					'suggest' => $this->buildSuggestConfig( 'suggest' ),
				) );
			}

			$result = $this->search( $originalTerm );

			if ( !$result->isOK() && $this->isParseError( $result ) ) {
				// Elasticsearch has reported a parse error and we've already logged it when we built the status
				// so at this point all we can do is retry the query as a simple query string query.
				$this->searchContext->setMainQuery( new \Elastica\Query\Simple( array(
					'simple_query_string' => array(
						'fields' => $fields,
						'query' => $queryStringQueryString,
						'default_operator' => 'AND',
					),
				) ) );
				// Not worth trying in this state.
				$this->searchContext->clearRescore();
				$this->searchContext->setSearchType( 'degraded_full_text' );
				$result = $this->search( $originalTerm );
				// If that doesn't work we're out of luck but it should.  There no guarantee it'll work properly
				// with the syntax we've built above but it'll do _something_ and we'll still work on fixing all
				// the parse errors that come in.
			}
		}

		return $result;
	}

	/**
	 * Builds a match query against $field for $title.  $title is munged to make title matching better more
	 * intuitive for users.
	 * @param string $field field containing the title
	 * @param string $title title query text to match against
	 * @param boolean $underscores true if the field contains underscores instead of spaces.  Defaults to false.
	 * @return \Elastica\Query\Match for matching $title to $field
	 */
	public function matchPage( $field, $title, $underscores = false ) {
		if ( $underscores ) {
			$title = str_replace( ' ', '_', $title );
		} else {
			$title = str_replace( '_', ' ', $title );
		}
		$match = new \Elastica\Query\Match();
		$match->setFieldQuery( $field, $title );

		return $match;
	}

	/**
	 * Builds an or between many categories that the page could be in.
	 * @param string[] $categories categories to match
	 * @return \Elastica\Query\BoolQuery|null A null return value means all values are filtered
	 *  and an empty result set should be returned.
	 */
	public function matchPageCategories( $categories ) {
		$filter = new \Elastica\Query\BoolQuery();
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
		foreach ( Title::newFromIDs( $ids ) as $title ) {
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
		sort( $titles, SORT_STRING );
		$pageIds = array();
		$likeDocs = array();
		foreach ( $titles as $title ) {
			$pageIds[] = $title->getArticleID();
			$likeDocs[] = array( '_id' => $title->getArticleID() );
		}

		// If no fields has been set we return no results.
		// This can happen if the user override this setting with field names that
		// are not allowed in $this->config->get( 'CirrusSearchMoreLikeThisAllowedFields (see Hooks.php)
		if( !$this->config->get( 'CirrusSearchMoreLikeThisFields' ) ) {
			return Status::newGood( new SearchResultSet( true ) /* empty */ );
		}

		// more like this queries are quite expensive and are suspected to be
		// triggering latency spikes. This allows redirecting more like this
		// queries to a different cluster
		$cluster = $this->config->get( 'CirrusSearchMoreLikeThisCluster' );
		if ( $cluster ) {
			$this->connection = Connection::getPool( $this->config, $cluster );
		}

		$this->searchContext->addSyntaxUsed( 'more_like' );
		$this->searchContext->setSearchType( 'more_like' );

		$moreLikeThisFields = $this->config->get( 'CirrusSearchMoreLikeThisFields' );
		$moreLikeThisUseFields = $this->config->get( 'CirrusSearchMoreLikeThisUseFields' );
		sort( $moreLikeThisFields );
		$query = new \Elastica\Query\MoreLikeThis();
		$query->setParams( $this->config->get( 'CirrusSearchMoreLikeThisConfig' ) );
		$query->setFields( $moreLikeThisFields );

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
			if ( !$found->isOK() ) {
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
			sort( $text, SORT_STRING );
			$likeDocs = array_merge( $likeDocs, $text );
		}

		/** @suppress PhanTypeMismatchArgument library is mis-annotated */
		$query->setLike( $likeDocs );
		$this->searchContext->setMainQuery( $query );

		if ( $options & Searcher::MORE_LIKE_THESE_ONLY_WIKIBASE ) {
			$this->searchContext->addFilter( new \Elastica\Query\Exists( 'wikibase_item' ) );
		}

		// highlight snippets are not great so it's worth running a match all query
		// to save cpu cycles
		$this->searchContext->setHighlightQuery( new \Elastica\Query\MatchAll() );

		return $this->search(
			implode( ', ', $titles ),
			$this->config->get( 'CirrusSearchMoreLikeThisTTL' )
		);
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
		$indexType = $this->connection->pickIndexTypeForNamespaces(
			$this->searchContext->getNamespaces()
		);

		// The worst case would be to have all ids duplicated in all available indices.
		// We set the limit accordingly
		$size = count ( $this->connection->getAllIndexSuffixesForNamespaces(
			$this->searchContext->getNamespaces()
		));
		$size *= count( $pageIds );
		return Util::doPoolCounterWork(
			'CirrusSearch-Search',
			$this->user,
			function() use ( $pageIds, $sourceFiltering, $indexType, $size ) {
				try {
					$this->start( "get of {indexType}.{pageIds}", array(
						'indexType' => $indexType,
						'pageIds' => array_map( 'intval', $pageIds ),
						'queryType' => 'get',
					) );
					// Shard timeout not supported on get requests so we just use the client side timeout
					$this->connection->setTimeout( $this->config->getElement( 'CirrusSearchClientSideSearchTimeout', 'default' ) );
					// We use a search query instead of _get/_mget, these methods are
					// theorically well suited for this kind of job but they are not
					// supported on aliases with multiple indices (content/general)
					$pageType = $this->connection->getPageType( $this->indexBaseName, $indexType );
					$query = new \Elastica\Query( new \Elastica\Query\Ids( null, $pageIds ) );
					$query->setParam( '_source', $sourceFiltering );
					$query->addParam( 'stats', 'get' );
					// We ignore limits provided to the searcher
					// otherwize we could return fewer results than
					// the ids requested.
					$query->setFrom( 0 );
					$query->setSize( $size );
					$resultSet = $pageType->search( $query, array( 'search_type' => 'query_then_fetch' ) );
					return $this->success( $resultSet->getResults() );
				} catch ( \Elastica\Exception\NotFoundException $e ) {
					// NotFoundException just means the field didn't exist.
					// It is up to the caller to decide if that is an error.
					return $this->success( array() );
				} catch ( \Elastica\Exception\ExceptionInterface $e ) {
					return $this->failure( $e );
				}
			});
	}

	/**
	 * @param string $name
	 * @return Status
	 */
	public function findNamespace( $name ) {
		return Util::doPoolCounterWork(
			'CirrusSearch-NamespaceLookup',
			$this->user,
			function() use ( $name ) {
				try {
					$this->start( "lookup namespace for {namespaceName}", array(
						'namespaceName' => $name,
						'query' => $name,
						'queryType' => 'namespace',
					) );
					$pageType = $this->connection->getNamespaceType( $this->indexBaseName );
					$match = new \Elastica\Query\Match();
					$match->setField( 'name', $name );
					$query = new \Elastica\Query( $match );
					$query->setParam( '_source', false );
					$query->addParam( 'stats', 'namespace' );
					$resultSet = $pageType->search( $query, array( 'search_type' => 'query_then_fetch' ) );
					return $this->success( $resultSet->getResults() );
				} catch ( \Elastica\Exception\ExceptionInterface $e ) {
					return $this->failure( $e );
				}
			});
	}

	/**
	 * @param string $term
	 * @param string $regex
	 * @param callable $callback
	 * @return string Modified $term
	 */
	private function extractSpecialSyntaxFromTerm( $term, $regex, $callback ) {
		return preg_replace_callback( $regex,
			function ( $matches ) use ( $callback ) {
				$result = $callback( $matches );
				if ( $result === '' ) {
					$this->searchContext->addSuggestPrefix( $matches[0] );
				}
				return $result;
			},
			$term
		);
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
	 * @param string $for
	 * @param int $cacheTTL Cache results into ObjectCache for $cacheTTL seconds
	 * @return Status results from the query transformed by the resultsType
	 */
	private function search( $for, $cacheTTL = 0 ) {
		if ( $this->limit <= 0 && ! $this->returnQuery ) {
			if ( $this->returnResult ) {
				return Status::newGood( array(
						'description' => 'Canceled due to offset out of bounds',
						'path' => '',
						'result' => array(),
				) );
			} else {
				return Status::newGood( $this->resultsType->createEmptyResult() );
			}
		}

		if ( $this->resultsType === null ) {
			$this->resultsType = new FullTextResultsType( FullTextResultsType::HIGHLIGHT_ALL );
		}

		$query = new Elastica\Query();
		$query->setParam( '_source', $this->resultsType->getSourceFiltering() );
		$query->setParam( 'fields', $this->resultsType->getFields() );

		$extraIndexes = array();
		$namespaces = $this->searchContext->getNamespaces();
		$indexType = $this->connection->pickIndexTypeForNamespaces( $namespaces );
		if ( $namespaces ) {
			$extraIndexes = $this->getAndFilterExtraIndexes();
			$this->searchContext->addFilter( new \Elastica\Query\Terms( 'namespace', $namespaces ) );
		}

		$this->installBoosts();
		$query->setQuery( $this->searchContext->getQuery() );

		$highlight = $this->searchContext->getHighlight( $this->resultsType );
		if ( $highlight ) {
			$query->setHighlight( $highlight );
		}

		if ( $this->searchContext->getSuggest() ) {
			if ( interface_exists( 'Elastica\\ArrayableInterface' ) ) {
				// Elastica 2.3.x.  For some reason it unwraps our suggest
				// query when we don't want it to, so wrap it one more time
				// to make the unwrap do nothing.
				$query->setParam( 'suggest', array(
					'suggest' => $this->searchContext->getSuggest()
				) );
			} else {
				$query->setParam( 'suggest', $this->searchContext->getSuggest() );
			}
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
			$this->searchContext->clearRescore();
		} elseif ( $this->searchContext->hasRescore() ) {
			$query->setParam( 'rescore', $this->searchContext->getRescore() );
		}

		$query->addParam( 'stats', $this->searchContext->getSearchType() );
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

		switch( $this->searchContext->getSearchType() ) {
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
			'queryType' => $this->searchContext->getSearchType(),
			'query' => $for,
			'limit' => $this->limit ?: null,
			// null means not requested, '' means not found. If found
			// parent::buildLogContext will replace the '' with an
			// actual suggestion.
			'suggestion' => $this->searchContext->getSuggest() ? '' : null,
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

		if ( $this->returnExplain ) {
			$query->setExplain( true );
		}
		if ( $this->returnResult || $this->returnExplain ) {
			// don't cache debugging queries
			$cacheTTL = 0;
		}

		$requestStats = MediaWikiServices::getInstance()->getStatsdDataFactory();
		if ( $cacheTTL > 0 ) {
			$cache = ObjectCache::getLocalClusterInstance();
			$key = $cache->makeKey( 'cirrussearch', 'search', md5(
				$search->getPath() .
				serialize( $search->getOptions() ) .
				serialize( $query->toArray() ) .
				serialize( $this->resultsType )
			) );
			$cacheResult = $cache->get( $key );
			$type = $this->searchContext->getSearchType();
			if ( $cacheResult ) {
				$requestStats->increment("CirrusSearch.query_cache.$type.hit");
				$this->successViaCache( $description, $logContext );
				return $cacheResult;
			} else {
				$requestStats->increment("CirrusSearch.query_cache.$type.miss");
			}
		}

		// Perform the search
		$result = Util::doPoolCounterWork(
			$poolCounterType,
			$this->user,
			function() use ( $search, $description, $logContext ) {
				try {
					$this->start( $description, $logContext );
					return $this->success( $search->search() );
				} catch ( \Elastica\Exception\ExceptionInterface $e ) {
					return $this->failure( $e );
				}
			},
			function( $error, $key, $userName ) use ( $description, $logContext ) {
				$forUserName = $userName ? "for {userName} " : '';
				LoggerFactory::getInstance( 'CirrusSearch' )->warning(
					/** @suppress PhanTypeMismatchArgument phan doesn't understand array addition */
					"Pool error {$forUserName}on key {key} during $description:  {error}",
					$logContext + array(
						'userName' => $userName,
						'key' => 'key',
						'error' => $error
					)
				);

				if ( $error === 'pool-queuefull' ) {
					if ( strpos( $key, 'nowait:CirrusSearch:_per_user' ) === 0 ) {
						$loggedIn = $this->user->isLoggedIn() ? 'logged-in' : 'anonymous';
						return Status::newFatal( "cirrussearch-too-busy-for-you-{$loggedIn}-error" );
					}
					if ( $this->searchContext->getSearchType() === 'regex' ) {
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

			$result->setResult( true, $this->resultsType->transformElasticsearchResult(
				$this->searchContext,
				$result->getValue()
			) );
			$isPartialResult = false;
			if ( isset( $responseData['timed_out'] ) && $responseData[ 'timed_out' ] ) {
				$isPartialResult = true;
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

			if ( $cacheTTL > 0 && !$isPartialResult ) {
				/** @suppress PhanUndeclaredVariable */
				$requestStats->increment("CirrusSearch.query_cache.$type.set");
				/** @suppress PhanUndeclaredVariable */
				$cache->set( $key, $result, $cacheTTL );
			}
		}


		return $result;
	}

	/**
	 * @param string[] $fields
	 * @param string[] $nearMatchFields
	 * @param string $queryString
	 * @param string $nearMatchQuery
	 * @return \Elastica\Query\Simple|\Elastica\Query\BoolQuery
	 */
	private function buildSearchTextQuery( array $fields, array $nearMatchFields, $queryString, $nearMatchQuery ) {
		$queryForMostFields = $this->buildSearchTextQueryForFields( $fields, $queryString,
				$this->config->getElement( 'CirrusSearchPhraseSlop', 'default' ), false );
		if ( $nearMatchQuery ) {
			// Build one query for the full text fields and one for the near match fields so that
			// the near match can run unescaped.
			$bool = new \Elastica\Query\BoolQuery();
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
	 * @param bool $isRescore
	 * @param bool $forHighlight
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
					'inline' => array(
						'multi_match' => array(
							'query' => '{{suggestion}}',
							'operator' => 'or',
							'minimum_should_match' => $suggestSettings['collate_minimum_should_match'],
							'type' => 'cross_fields',
							'fields' => $collateFields
						),
					),
				),
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
	 * the possible fields otherwise. This prevents applying and compiling
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
	 * @param string $fieldSuffix suffix to add to field names
	 * @param boolean $allFieldAllowed can we use the all field?  False for
	 *    collecting phrases for the highlighter.
	 * @return string[] array of fields to query
	 */
	public function buildFullTextSearchFields( $weight, $fieldSuffix, $allFieldAllowed ) {
		if ( $this->config->getElement( 'CirrusSearchAllFields', 'use' ) && $allFieldAllowed ) {
			if ( $fieldSuffix === '.near_match' ) {
				// The near match fields can't shard a root field because field fields need it -
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
		$namespaces = $this->searchContext->getNamespaces();
		if ( !$namespaces || in_array( NS_FILE, $namespaces ) ) {
			$fileTextWeight = $weight * $searchWeights[ 'file_text' ];
			$fields[] = "file_text${fieldSuffix}^${fileTextWeight}";
		}
		return $fields;
	}

	/**
	 * Retrieve the extra indexes for our searchable namespaces, if any
	 * exist. If they do exist, also add our wiki to our notFilters so
	 * we can filter out duplicates properly.
	 *
	 * @return string[]
	 */
	protected function getAndFilterExtraIndexes() {
		if ( $this->searchContext->getLimitSearchToLocalWiki() ) {
			return array();
		}
		$extraIndexes = OtherIndexes::getExtraIndexesForNamespaces(
			$this->searchContext->getNamespaces()
		);
		if ( $extraIndexes ) {
			$this->searchContext->addNotFilter( new \Elastica\Query\Term(
				array( 'local_sites_with_dupe' => $this->indexBaseName )
			) );
		}
		return $extraIndexes;
	}

	/**
	 * If there is any boosting to be done munge the the current query to get it right.
	 */
	private function installBoosts() {
		if ( $this->sort !== 'relevance' ) {
			// Boosts are irrelevant if you aren't sorting by, well, relevance
			return;
		}

		$builder = new RescoreBuilder( $this->searchContext );
		$this->searchContext->mergeRescore( $builder->build() );
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
		$status = $this->findNamespace( $namespaceName );
		// Failure case is already logged so just handle success case
		if ( !$status->isOK() ) {
			return;
		}
		$foundNamespace = $status->getValue();
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
	 * @return SearchContext
	 */
	public function getSearchContext() {
		return $this->searchContext;
	}
}
