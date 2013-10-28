<?php
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
class CirrusSearchSearcher {
	const SUGGESTION_NAME_TITLE = 'title';
	const SUGGESTION_NAME_REDIRECT = 'redirect';
	const SUGGESTION_NAME_TEXT = 'text_suggestion';
	const SUGGESTION_HIGHLIGHT_PRE = '<em>';
	const SUGGESTION_HIGHLIGHT_POST = '</em>';
	const HIGHLIGHT_PRE = '<span class="searchmatch">';
	const HIGHLIGHT_POST = '</span>';

	/**
	 * Maximum title length that we'll check in prefix search.  Since titles can
	 * be 255 bytes in length we're setting this to 255 characters but this
	 * might cause bloat in the title's prefix index so we'll have to keep an
	 * eye on this.
	 */
	const MAX_PREFIX_SEARCH = 255;

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
	private $namespaces;
	/**
	 * @var CirrusSearchResultsType|null type of results.  null defaults to CirrusSearchFullTextResultsType
	 */
	private $resultsType;

	// These fields are filled in by the particule search methods
	private $query = null;
	private $filters = array();
	private $suggest = null;
	/**
	 * @var null|array of rescore configuration as used by elasticsearch.  The query needs to be an Elastica query.
	 */
	private $rescore = null;
	/**
	 * @var string description of the current operation used in logging errors
	 */
	private $description;

	public function __construct( $offset, $limit, $namespaces ) {
		$this->offset = $offset;
		$this->limit = $limit;
		$this->namespaces = $namespaces;
	}

	/**
	 * @param CirrusSearchResultsType $resultsType results type to return
	 */
	public function setResultsType( $resultsType ) {
		$this->resultsType = $resultsType;
	}

	/**
	 * Perform a prefix search.
	 * @param $search
	 * @param array(string) of titles
	 */
	public function prefixSearch( $search ) {
		global $wgCirrusSearchPrefixSearchStartsWithAnyWord;
		$requestLength = strlen( $search );
		if ( $requestLength > self::MAX_PREFIX_SEARCH ) {
			throw new UsageException( 'Prefix search requset was longer longer than the maximum allowed length.' .
				" ($requestLength > " . self::MAX_PREFIX_SEARCH . ')', 'request_too_long', 400 );
		}
		wfDebugLog( 'CirrusSearch', "Prefix searching:  $search" );

		if ( $wgCirrusSearchPrefixSearchStartsWithAnyWord ) {
			$match = new \Elastica\Query\Match();
			$match->setField( 'title.word_prefix', array(
				'query' => $search,
				'analyzer' => 'plain',
				'operator' => 'and',
			) );
			$this->filters[] = new \Elastica\Filter\Query( $match );
		} else {
			$this->filters[] = $this->buildPrefixFilter( $search );
		}

		$this->description = "prefix search for '$search'";
		$this->buildFullTextResults = false;
		return $this->search();
	}

	/**
	 * Search articles with provided term.
	 * @param string $term
	 * @param boolean $showRedirects
	 * @return CirrusSearchResultSet|null|SearchResultSet|Status
	 */
	public function searchText( $term, $showRedirects ) {
		wfProfileIn( __METHOD__ );
		global $wgCirrusSearchPhraseRescoreBoost;
		global $wgCirrusSearchPhraseRescoreWindowSize;
		global $wgCirrusSearchPhraseUseText;
		wfDebugLog( 'CirrusSearch', "Searching:  $term" );

		// Transform Mediawiki specific syntax to filters and extra (pre-escaped) query string
		$originalTerm = $term;
		$extraQueryStrings = array();
		// Handle title prefix notation
		wfProfileIn( __METHOD__ . '-prefix-filter' );
		$prefixPos = strpos( $term, 'prefix:' );
		if ( $prefixPos !== false ) {
			$value = substr( $term, 7 + $prefixPos );
			if ( strlen( $value ) > 0 ) {
				$term = substr( $term, 0, max( 0, $prefixPos - 1 ) );
				// Suck namespaces out of $value
				$cirrusSearchEngine = new CirrusSearch();
				$value = trim( $cirrusSearchEngine->replacePrefixes( $value ) );
				$this->namespaces = $cirrusSearchEngine->namespaces;
				// If the namespace prefix wasn't the entire prefix filter then add a filter for the title
				if ( strpos( $value, ':' ) !== strlen( $value ) - 1 ) {
					$this->filters[] = $this->buildPrefixFilter( $value );
				}
			}
		}
		wfProfileOut( __METHOD__ . '-prefix-filter' );
		//Handle other filters
		wfProfileIn( __METHOD__ . '-other-filters' );
		$filters = $this->filters;
		$term = preg_replace_callback(
			'/(?<key>[^ ]{6,10}):(?<value>(?:"[^"]+")|(?:[^ "]+)) ?/',
			function ( $matches ) use ( &$filters, &$extraQueryStrings ) {
				$key = $matches['key'];
				$value = $matches['value'];  // Note that if the user supplied quotes they are not removed
				switch ( $key ) {
					case 'incategory':
						$match = new \Elastica\Query\Match();
						$match->setFieldQuery( 'category', trim( $value, '"' ) );
						$filters[] = new \Elastica\Filter\Query( $match );
						return '';
					case 'prefix':
						return "$value* ";
					case 'intitle':
						$filters[] = new \Elastica\Filter\Query( new \Elastica\Query\Field(
							'title', CirrusSearchSearcher::fixupQueryString( $value ) ) );
						return "$value ";
					default:
						return $matches[0];
				}
			},
			$term
		);
		wfProfileOut( __METHOD__ . '-other-filters' );
		wfProfileIn( __METHOD__ . '-phrase-query-finder' );
		$term = preg_replace_callback(
				'/(?<main>"([^"]+)"(?:~[0-9]+)?)(?<fuzzy>~)?/',
				function ( $matches ) use ( $showRedirects, &$extraQueryStrings ) {
					$main = $matches[ 'main' ];
					if ( isset( $matches[ 'fuzzy' ] ) ) {
						return $main;
					} else {
						$query = join( ' OR ',
								CirrusSearchSearcher::buildFullTextSearchFields( $showRedirects, ".plain:$main" ) );
						$extraQueryStrings[] = "($query)";
					}
					return '';
				},
				$term
		);
		wfProfileOut( __METHOD__ . '-phrase-query-finder' );
		$this->filters = $filters;


		// Actual text query
		if ( trim( $term ) !== '' || $extraQueryStrings ) {
			wfProfileIn( __METHOD__ . '-build-query' );
			$fixedTerm = self::fixupQueryString( $term );
			$queryStringQueryString = trim( implode( ' ', $extraQueryStrings ) . ' ' . $fixedTerm );
			$fields = CirrusSearchSearcher::buildFullTextSearchFields( $showRedirects );
			$this->query = $this->buildSearchTextQuery( $fields, $queryStringQueryString );

			// Only do a phrase match rescore if the query doesn't include any phrases
			if ( $wgCirrusSearchPhraseRescoreBoost > 1.0 && !preg_match( '/"[^ "]+ [^"]+"/', $fixedTerm ) ) {
				$this->rescore = array(
					'window_size' => $wgCirrusSearchPhraseRescoreWindowSize,
					'query' => array(
						'rescore_query' => $this->buildSearchTextQuery( $fields, '"' . $fixedTerm . '"' ),
						'query_weight' => 1.0,
						'rescore_query_weight' => $wgCirrusSearchPhraseRescoreBoost,
					)
				);
			}

			$this->suggest = array(
				'text' => $term,
				self::SUGGESTION_NAME_TITLE => $this->buildSuggestConfig( 'title.suggest' ),
			);
			if ( $showRedirects ) {
				$this->suggest[ self::SUGGESTION_NAME_REDIRECT ] = $this->buildSuggestConfig( 'redirect.title.suggest' );
			}
			if ( $wgCirrusSearchPhraseUseText ) {
				$this->suggest[ self::SUGGESTION_NAME_TEXT ] = $this->buildSuggestConfig( 'text.suggest' );
			}
			wfProfileOut( __METHOD__ . '-build-query' );
		}
		$this->description = "full text search for '$originalTerm'";
		$result = $this->search();
		wfProfileOut( __METHOD__ );
		return $result;
	}

	/**
	 * @param $id article id to search
	 * @return CirrusSearchResultSet|null|SearchResultSet|Status
	 */
	public function moreLikeThisArticle( $id ) {
		wfProfileIn( __METHOD__ );
		global $wgCirrusSearchMoreLikeThisConfig;

		// It'd be better to be able to have Elasticsearch fetch this during the query rather than make
		// two passes but it doesn't support that at this point
		$found = $this->get( $id, array( 'text' ) );
		if ( !$found->isOk() ) {
			return $found;
		}
		$found = $found->getValue();
		if ( $found === null ) {
			// If the pge doesn't exist we can't find any articles like it
			return null;
		}

		$this->query = new \Elastica\Query\MoreLikeThis();
		$this->query->setParams( $wgCirrusSearchMoreLikeThisConfig );
		$this->query->setLikeText( Sanitizer::stripAllTags( $found->text ) );
		$this->query->setFields( array( 'text' ) );
		$idFilter = new \Elastica\Filter\Ids();
		$idFilter->addId( $id );
		$this->filters[] = new \Elastica\Filter\BoolNot( $idFilter );

		$result = $this->search();
		wfProfileOut( __METHOD__ );
		return $result;
	}

	/**
	 * Get the page with $id.
	 * @param $id int page id
	 * @param $fields array(string) fields to fetch
	 * @return Status containing page data, null if not found, or a if there was an error
	 */
	public function get( $id, $fields ) {
		wfProfileIn( __METHOD__ );
		$indexType = $this->pickIndexTypeFromNamespaces();
		$getWork = new PoolCounterWorkViaCallback( 'CirrusSearch-Search', "_elasticsearch", array(
			'doWork' => function() use ( $indexType, $id, $fields ) {
				try {
					$result = CirrusSearchConnection::getPageType( $indexType )->getDocument( $id, array(
						'fields' => $fields,
					) );
					return Status::newGood( $result );
				} catch ( \Elastica\Exception\NotFoundException $e ) {
					// NotFoundException just means the field didn't exist.
					// It is up to the called to decide if that is and error.
					return Status::newGood( null );
				} catch ( \Elastica\Exception\ExceptionInterface $e ) {
					wfLogWarning( "Search backend error during get for $id.  Error message is:  " . $e->getMessage() );
					$status = new Status();
					$status->warning( 'cirrussearch-backend-error' );
					return $status;
				}
			}
		) );
		$result = $getWork->execute();
		wfProfileOut( __METHOD__ );
		return $result;
	}

	/**
	 * Powers full-text-like searches which means pretty much everything but prefixSearch.
	 * @return CirrusSearchResultSet|null|SearchResultSet|Status
	 */
	private function search() {
		wfProfileIn( __METHOD__ );
		global $wgCirrusSearchMoreAccurateScoringMode;

		if ( $this->resultsType === null ) {
			$this->resultsType = new CirrusSearchFullTextResultsType();
		}

		$query = new Elastica\Query();
		$query->setFields( $this->resultsType->getFields() );
		$query->setQuery( self::boostQuery( $this->query ) );

		$highlight = $this->resultsType->getHighlightingConfiguration();
		if ( $highlight ) {
			$query->setHighlight( $highlight );
		}
		if ( $this->suggest ) {
			$query->setParam( 'suggest', $this->suggest );
		}
		if( $this->offset ) {
			$query->setFrom( $this->offset );
		}
		if( $this->limit ) {
			$query->setSize( $this->limit );
		}
		if ( $this->rescore ) {
			// Wrap the rescore query in the boostQuery just as we wrap the regular query.
			$this->rescore[ 'query' ][ 'rescore_query' ] =
				self::boostQuery( $this->rescore[ 'query' ][ 'rescore_query' ] )->toArray();
			$query->setParam( 'rescore', $this->rescore );
		}

		if ( $this->namespaces ) {
			$this->filters[] = new \Elastica\Filter\Terms( 'namespace', $this->namespaces );
		}
		if ( count( $this->filters ) > 1 ) {
			$mainFilter = new \Elastica\Filter\Bool();
			foreach ( $this->filters as $filter ) {
				$mainFilter->addMust( $filter );
			}
			$query->setFilter( $mainFilter );
		} else if ( count( $this->filters ) === 1 ) {
			$query->setFilter( $this->filters[0] );
		}

		$queryOptions = array();
		if ( $wgCirrusSearchMoreAccurateScoringMode ) {
			$queryOptions[ 'search_type' ] = 'dfs_query_then_fetch';
		}

		// Perform the search
		$description = $this->description;
		$indexType = $this->pickIndexTypeFromNamespaces();
		$work = new PoolCounterWorkViaCallback( 'CirrusSearch-Search', "_elasticsearch", array(
			'doWork' => function() use ( $indexType, $query, $queryOptions, $description ) {
				try {
					$result = CirrusSearchConnection::getPageType( $indexType )->search( $query, $queryOptions );
					wfDebugLog( 'CirrusSearch', 'Search completed in ' . $result->getTotalTime() . ' millis' );
					return $result;
				} catch ( \Elastica\Exception\ExceptionInterface $e ) {
					wfLogWarning( "Search backend error during $description.  Error message is:  " . $e->getMessage() );
					return false;
				}
			}
		) );
		$result = $work->execute();
		if ( !$result ) {
			$status = new Status();
			$status->warning( 'cirrussearch-backend-error' );
			return $status;
		}
		$result = $this->resultsType->transformElasticsearchResult( $result );
		wfProfileOut( __METHOD__ );
		return $result;
	}

	private function buildSearchTextQuery( $fields, $query ) {
		global $wgCirrusSearchPhraseSlop;
		$query = new \Elastica\Query\QueryString( $query );
		$query->setFields( $fields );
		$query->setAutoGeneratePhraseQueries( true );
		$query->setPhraseSlop( $wgCirrusSearchPhraseSlop );
		$query->setDefaultOperator( 'AND' );
		$query->setRewrite( 'constant_score_filter' ); // Work around for Elasticsearch #3754
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
					),
				),
				'highlight' => array(
					'pre_tag' => self::SUGGESTION_HIGHLIGHT_PRE,
					'post_tag' => self::SUGGESTION_HIGHLIGHT_POST,
				),
			),
		);
	}

	/**
	 * Build fields searched by full text search.
	 * @param $includeRedirects bool show redirects be included
	 * @param $fieldSuffix string suffux to add to field names.  Defaults to ''.
	 * @return array(string) of fields to query
	 */
	public static function buildFullTextSearchFields( $includeRedirects, $fieldSuffix = '' ) {
		global $wgCirrusSearchWeights;
		$fields = array(
			'title' . $fieldSuffix . '^' . $wgCirrusSearchWeights[ 'title' ],
			'heading' . $fieldSuffix . '^' . $wgCirrusSearchWeights[ 'heading' ],
			'text' . $fieldSuffix,
		);
		if ( $includeRedirects ) {
			$fields[] = 'redirect.title' . $fieldSuffix . '^' . $wgCirrusSearchWeights[ 'redirect' ];
		}
		return $fields;
	}

	/**
	 * Pick the index type to search bases on the list of namespaces to search.
	 * @return mixed index type in which to search
	 */
	private function pickIndexTypeFromNamespaces() {
		if ( !$this->namespaces ) {
			return false; // False selects both index types
		}
		$needsContent = false;
		$needsGeneral = false;
		foreach ( $this->namespaces as $namespace ) {
			if ( MWNamespace::isContent( $namespace ) ) {
				$needsContent = true;
			} else {
				$needsGeneral = true;
			}
			if ( $needsContent && $needsGeneral ) {
				return false; // False selects both index types
			}
		}
		return $needsContent ?
			CirrusSearchConnection::CONTENT_INDEX_TYPE :
			CirrusSearchConnection::GENERAL_INDEX_TYPE;
	}

	private function buildPrefixFilter( $search ) {
		$match = new \Elastica\Query\Match();
		$match->setField( 'title.prefix', array(
			'query' => substr( $search, 0, self::MAX_PREFIX_SEARCH ),
			'analyzer' => 'lowercase_keyword',
		) );
		return new \Elastica\Filter\Query( $match );
	}

	/**
	 * Escape some special characters that we don't want users to pass into query strings directly.
	 * These special characters _aren't_ escaped: *, ~, and "
	 * *: Do a prefix or postfix search against the stemmed text which isn't strictly a good
	 * idea but this is so rarely used that adding extra code to flip prefix searches into
	 * real prefix searches isn't really worth it.  The same goes for postfix searches but
	 * doubly because we don't have a postfix index (backwards ngram.)
	 * ~: Do a fuzzy match against the stemmed text which isn't strictly a good idea but it
	 * gets the job done and fuzzy matches are a really rarely used feature to be creating an
	 * extra index for.
	 * ": Perform a phrase search for the quoted term.  If the "s aren't balanced we insert one
	 * at the end of the term to make sure elasticsearch doesn't barf at us.
	 * +/-/!/||/&&: Symbols meaning AND, NOT, NOT, OR, and AND respectively.  - was supported by
	 * LuceneSearch so we need to allow that one but there is no reason not to allow them all.
	 */
	public static function fixupQueryString( $string ) {
		wfProfileIn( __METHOD__ );
		$string = preg_replace( '/(
				\/|		(?# no regex searches allowed)
				\(|     (?# no user supplied groupings)
				\)|
				\{|     (?# no exclusive range queries)
				}|
				\[|     (?# no inclusive range queries either)
				]|
				\^|     (?# no user supplied boosts at this point, though I cant think why)
				:|		(?# no specifying your own fields)
				\\\
			)/x', '\\\$1', $string );
		// If the string doesn't have balanced quotes then add a quote on the end so Elasticsearch
		// can parse it.
		$inQuote = false;
		$inEscape = false;
		$len = strlen( $string );
		for ( $i = 0; $i < $len; $i++ ) {
			if ( $inEscape ) {
				continue;
			}
			switch ( $string[ $i ] ) {
			case '"':
				$inQuote = !$inQuote;
				break;
			case '\\':
				$inEscape = true;
			}
		}
		if ( $inQuote ) {
			$string = $string . '"';
		}
		// Turn bad fuzzy searches into searches that contain a ~
		$string = preg_replace_callback( '/(?<leading>[^\s"])~(?<trailing>\S+)/', function ( $matches ) {
			if ( preg_match( '/0|(?:0?\.[0-9]+)|(?:1(?:\.0)?)/', $matches[ 'trailing' ] ) ) {
				return $matches[ 0 ];
			} else {
				return $matches[ 'leading' ] . '\\~' . $matches[ 'trailing' ];
			}
		}, $string );
		// Turn bad proximity searches into searches that contain a ~
		$string = preg_replace_callback( '/"~(?<trailing>\S*)/', function ( $matches ) {
			if ( preg_match( '/[0-9]+/', $matches[ 'trailing' ] ) ) {
				return $matches[ 0 ];
			} else {
				return '"\\~' . $matches[ 'trailing' ];
			}
		}, $string );
		// Escape +, -, and ! when not followed immediately by a term.
		$string = preg_replace( '/(?:\\+|\\-|\\!)(?:\s|$)/', '\\\\$0', $string );
		// Lowercase AND and OR when not surrounded on both sides by a term.
		// Lowercase NOT when it doesn't have a term after it.
		$string = preg_replace_callback( '/(?:AND|OR|NOT)\s*$/', 'CirrusSearchSearcher::lowercaseMatched', $string );
		$string = preg_replace_callback( '/^\s*(?:AND|OR)/', 'CirrusSearchSearcher::lowercaseMatched', $string );
		wfProfileOut( __METHOD__ );
		return $string;
	}

	private static function lowercaseMatched( $matches ) {
		return strtolower( $matches[ 0 ] );
	}

	/**
	 * Wrap query in link based boosts.
	 * @param $query null|Elastica\Query optional query to boost.  if null the match_all is assumed
	 * @return query that will run $query and boost results based on links
	 */
	private static function boostQuery( $query = null ) {
		return new \Elastica\Query\CustomScore( "_score * log10(doc['links'].value + doc['redirect_links'].value + 2)", $query );
	}
}

interface CirrusSearchResultsType {
	function getFields();
	function getHighlightingConfiguration();
	function transformElasticsearchResult( $result );
}

class CirrusSearchTitleResultsType {
	public function getFields() {
		return array( 'namespace', 'title' );
	}
	public function getHighlightingConfiguration() {
		return null;
	}
	public function transformElasticsearchResult( $result ) {
		$results = array();
		foreach( $result->getResults() as $r ) {
			$results[] = Title::makeTitle( $r->namespace, $r->title )->getPrefixedText();
		}
		return $results;
	}
}

class CirrusSearchFullTextResultsType {
	public function getFields() {
		return array( 'id', 'title', 'namespace', 'redirect', 'textLen', 'text_bytes', 'text_words' );
	}
	/**
	 * Setup highlighting.
	 * Don't fragment title because it is small.
	 * Get just one fragment from the text because that is all we will display.
	 * Get one fragment from redirect title and heading each or else they
	 * won't be sorted by score.
	 * @return array of highlighting configuration
	 */
	public function getHighlightingConfiguration() {
		return array(
			'order' => 'score',
			'pre_tags' => array( CirrusSearchSearcher::HIGHLIGHT_PRE ),
			'post_tags' => array( CirrusSearchSearcher::HIGHLIGHT_POST ),
			'fields' => array(
				'title' => array( 'number_of_fragments' => 0 ),
				'text' => array( 'number_of_fragments' => 1 ),
				'redirect.title' => array( 'number_of_fragments' => 1, 'type' => 'plain' ),
				'heading' => array( 'number_of_fragments' => 1, 'type' => 'plain' ),
				'title.plain' => array( 'number_of_fragments' => 0 ),
				'text.plain' => array( 'number_of_fragments' => 1 ),
				'redirect.title.plain' => array( 'number_of_fragments' => 1, 'type' => 'plain' ),
				'heading.plain' => array( 'number_of_fragments' => 1, 'type' => 'plain' ),
			),
		);
	}
	public function transformElasticsearchResult( $result ) {
		return new CirrusSearchResultSet( $result );
	}
}

/**
 * A set of results from Elasticsearch.
 */
class CirrusSearchResultSet extends SearchResultSet {
	/**
	 * @var string|null lazy built escaped copy of CirrusSearchSearcher::SUGGESTION_HIGHLIGHT_PRE
	 */
	private static $suggestionHighlightPreEscaped = null;
	/**
	 * @var string|null lazy built escaped copy of CirrusSearchSearcher::SUGGESTION_HIGHLIGHT_POST
	 */
	private static $suggestionHighlightPostEscaped = null;

	private $result, $hits, $totalHits, $suggestionQuery, $suggestionSnippet;

	public function __construct( $res ) {
		$this->result = $res;
		$this->hits = $res->count();
		$this->totalHits = $res->getTotalHits();
		$suggestion = $this->findSuggestion();
		$this->suggestionQuery = $suggestion[ 'text' ];
		$this->suggestionSnippet = self::escapeHighlightedSuggestion( $suggestion[ 'highlighted' ] );
	}

	private function findSuggestion() {
		// TODO some kind of weighting?
		$suggest = $this->result->getResponse()->getData();
		if ( !isset( $suggest[ 'suggest' ] ) ) {
			return null;
		}
		$suggest = $suggest[ 'suggest' ];
		// Elasticsearch will send back the suggest element but no sub suggestion elements if the wiki is empty.
		// So we should check to see if they exist even though in normal operation they always will.
		if ( isset( $suggest[ CirrusSearchSearcher::SUGGESTION_NAME_TITLE ] ) ) {
			foreach ( $suggest[ CirrusSearchSearcher::SUGGESTION_NAME_TITLE ][ 0 ][ 'options' ] as $option ) {
				return $option;
			}
		}
		// If the user doesn't search against redirects we don't check them for suggestions so the result might not be there.
		if ( isset( $suggest[ CirrusSearchSearcher::SUGGESTION_NAME_REDIRECT ] ) ) {
			foreach ( $suggest[ CirrusSearchSearcher::SUGGESTION_NAME_REDIRECT ][ 0 ][ 'options' ] as $option ) {
				return $option;
			}
		}
		// This suggestion type is optional, configured in LocalSettings.
		if ( isset( $suggest[ CirrusSearchSearcher::SUGGESTION_NAME_TEXT ] ) ) {
			foreach ( $suggest[ CirrusSearchSearcher::SUGGESTION_NAME_TEXT ][ 0 ][ 'options' ] as $option ) {
				return $option;
			}
		}
		return null;
	}

	/**
	 * Escape a highlighted suggestion coming back from Elasticsearch.
	 * @param $suggestion string suggestion from elasticsearch
	 * @return string $suggestion with html escaped _except_ highlighting pre and post tags
	 */
	private static function escapeHighlightedSuggestion( $suggestion ) {
		if ( self::$suggestionHighlightPreEscaped === null ) {
			self::$suggestionHighlightPreEscaped =
				htmlspecialchars( CirrusSearchSearcher::SUGGESTION_HIGHLIGHT_PRE );
			self::$suggestionHighlightPostEscaped =
				htmlspecialchars( CirrusSearchSearcher::SUGGESTION_HIGHLIGHT_POST );
		}
		return str_replace( array( self::$suggestionHighlightPreEscaped, self::$suggestionHighlightPostEscaped ),
			array( CirrusSearchSearcher::SUGGESTION_HIGHLIGHT_PRE, CirrusSearchSearcher::SUGGESTION_HIGHLIGHT_POST ),
			htmlspecialchars( $suggestion ) );
	}

	public function hasResults() {
		return $this->totalHits > 0;
	}

	public function getTotalHits() {
		return $this->totalHits;
	}

	public function numRows() {
		return $this->hits;
	}

	public function hasSuggestion() {
		return $this->suggestionQuery !== null;
	}

	public function getSuggestionQuery() {
		return $this->suggestionQuery;
	}

	public function getSuggestionSnippet() {
		return $this->suggestionSnippet;
	}

	public function next() {
		$current = $this->result->current();
		if ( $current ) {
			$this->result->next();
			return new CirrusSearchResult( $current );
		}
		return false;
	}
}

/**
 * An individual search result from Elasticsearch.
 */
class CirrusSearchResult extends SearchResult {
	/**
	 * @var string|null lazy built escaped copy of CirrusSearchSearcher::HIGHLIGHT_PRE
	 */
	private static $highlightPreEscaped = null;
	/**
	 * @var string|null lazy built escaped copy of CirrusSearchSearcher::HIGHLIGHT_POST
	 */
	private static $highlightPostEscaped = null;

	private $titleSnippet;
	private $redirectTitle, $redirectSnipppet;
	private $sectionTitle, $sectionSnippet;
	private $textSnippet;
	private $wordCount;
	private $byteSize;

	public function __construct( $result ) {
		$title = Title::makeTitle( $result->namespace, $result->title );
		$this->initFromTitle( $title );
		// TODO temporary hack until text_words and text_bytes are fully populated.
		$this->wordCount = $result->text_words === null ? $result->textLen : $result->text_words;
		$this->byteSize = $result->text_bytes === null ? $result->textLen : $result->text_bytes;
		$highlights = $result->getHighlights();
		// Hack for https://github.com/elasticsearch/elasticsearch/issues/3750
		$highlights = $this->swapInPlainHighlighting( $highlights, 'title' );
		$highlights = $this->swapInPlainHighlighting( $highlights, 'redirect.title' );
		$highlights = $this->swapInPlainHighlighting( $highlights, 'text' );
		$highlights = $this->swapInPlainHighlighting( $highlights, 'heading' );
		if ( isset( $highlights[ 'title' ] ) ) {
			$nstext = '';
			if ( $title->getNamespace() !== 0 ) {
				$nstext = $title->getNsText() . ':';
			}
			$this->titleSnippet = $nstext . self::escapeHighlightedText( $highlights[ 'title' ][ 0 ] );
		} else {
			$this->titleSnippet = '';
		}
		if ( !isset( $highlights[ 'title' ] ) && isset( $highlights[ 'redirect.title' ] ) ) {
			$this->redirectSnipppet = self::escapeHighlightedText( $highlights[ 'redirect.title' ][ 0 ] );
			$this->redirectTitle = $this->findRedirectTitle( $result->redirect );
		} else {
			$this->redirectSnipppet = '';
			$this->redirectTitle = null;
		}
		if ( isset( $highlights[ 'text' ] ) ) {
			$this->textSnippet = self::escapeHighlightedText( $highlights[ 'text' ][ 0 ] );
		} else {
			// This whole thing is a work around for Elasticsearch #1171.
			list( $contextLines, $contextChars ) = SearchEngine::userHighlightPrefs();
			// Inittext is forbidden because it uses the default SearchEngine's getTextFromContent.  No good!
			if ( $this->mRevision != null ) {
				$this->mText = SearchEngine::create( 'CirrusSearch' )
					->getTextFromContent( $this->mTitle, $this->mRevision->getContent() );
			} else {
				$this->mText = '';
			}
			// This is kind of lame because it only is nice for space delimited languages
			$matches = array();
			$text = Sanitizer::stripAllTags( $this->mText );
			if ( preg_match( "/^((?:.|\n){0,$contextChars})[\\s\\.\n]/", $text, $matches ) ) {
				$text = $matches[1];
			}
			$this->textSnippet = implode( "\n", array_slice( explode( "\n", $text ), 0, $contextLines ) );
		}
		if ( isset( $highlights[ 'heading' ] ) ) {
			$this->sectionSnippet = self::escapeHighlightedText( $highlights[ 'heading' ][ 0 ] );
			$this->sectionTitle = $this->findSectionTitle();
		} else {
			$this->sectionSnippet = '';
			$this->sectionTitle = null;
		}
	}

	/**
	 * Swap plain highlighting into the highlighting field if there isn't any normal highlighting.
	 * @var $highlights array of highlighting results
	 * @var $name string normal field name
	 * @return $highlights with $name replaced with plain field results if $name isn't in $highlights
	 */
	private function swapInPlainHighlighting( $highlights, $name ) {
		if ( !isset( $highlights[ $name ] ) && isset( $highlights[ "$name.plain" ] ) ) {
			$highlights[ $name ] = $highlights[ "$name.plain" ];
		}
		return $highlights;
	}

	/**
	 * Escape highlighted text coming back from Elasticsearch.
	 * @param $snippet string highlighted snippet returned from elasticsearch
	 * @return string $snippet with html escaped _except_ highlighting pre and post tags
	 */
	private static function escapeHighlightedText( $snippet ) {
		if ( self::$highlightPreEscaped === null ) {
			self::$highlightPreEscaped = htmlspecialchars( CirrusSearchSearcher::HIGHLIGHT_PRE );
			self::$highlightPostEscaped = htmlspecialchars( CirrusSearchSearcher::HIGHLIGHT_POST );
		}
		return str_replace( array( self::$highlightPreEscaped, self::$highlightPostEscaped ),
			array( CirrusSearchSearcher::HIGHLIGHT_PRE, CirrusSearchSearcher::HIGHLIGHT_POST ),
			htmlspecialchars( $snippet ) );
	}

	/**
	 * Build the redirect title from the highlighted redirect snippet.
	 * @param array $redirects Array of redirects stored as arrays with 'title' and 'namespace' keys
	 * @return Title object representing the redirect
	 */
	private function findRedirectTitle( $redirects ) {
		$title = $this->stripHighlighting( $this->redirectSnipppet );
		// Grab the redirect that matches the highlighted title with the lowest namespace.
		// That is pretty arbitrary but it prioritizes 0 over others.
		$best = null;
		foreach ( $redirects as $redirect ) {
			if ( $redirect[ 'title' ] === $title && ( $best === null || $best[ 'namespace' ] > $redirect ) ) {
				$best = $redirect;
			}
		}
		if ( $best === null ) {
			wfLogWarning( "Search backend highlighted a redirect ($title) but didn't return it." );
		}
		return Title::makeTitleSafe( $redirect[ 'namespace' ], $redirect[ 'title' ] );
	}

	private function findSectionTitle() {
		$heading = $this->stripHighlighting( $this->sectionSnippet );
		return Title::makeTitle(
			$this->getTitle()->getNamespace(),
			$this->getTitle()->getDBkey(),
			Title::escapeFragmentForURL( $heading )
		);
	}

	private function stripHighlighting( $highlighted ) {
		$markers = array( CirrusSearchSearcher::HIGHLIGHT_PRE, CirrusSearchSearcher::HIGHLIGHT_POST );
		return str_replace( $markers, '', $highlighted );
	}

	public function getTitleSnippet( $terms ) {
		return $this->titleSnippet;
	}

	public function getRedirectTitle() {
		return $this->redirectTitle;
	}

	public function getRedirectSnippet( $terms ) {
		return $this->redirectSnipppet;
	}

	public function getTextSnippet( $terms ) {
		return $this->textSnippet;
	}

	public function getSectionSnippet() {
		return $this->sectionSnippet;
	}

	public function getSectionTitle() {
		return $this->sectionTitle;
	}

	public function getWordCount() {
		return $this->wordCount;
	}

	public function getByteSize() {
		return $this->byteSize;
	}
}
