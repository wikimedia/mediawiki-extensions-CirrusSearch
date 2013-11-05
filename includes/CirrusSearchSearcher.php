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
			function ( $matches ) use ( &$filters ) {
				$key = $matches['key'];
				$value = $matches['value'];  // Note that if the user supplied quotes they are not removed
				switch ( $key ) {
					case 'incategory':
						$match = new \Elastica\Query\Match();
						$match->setFieldQuery( 'category', trim( $value, '"' ) );
						$filters[] = new \Elastica\Filter\Query( $match );
						return '';
					case 'intitle':
						$filters[] = new \Elastica\Filter\Query( new \Elastica\Query\Field( 'title',
							CirrusSearchSearcher::fixupWholeQueryString(
								CirrusSearchSearcher::fixupQueryStringPart( $value )
							) ) );
						return "$value ";
					default:
						return $matches[0];
				}
			},
			$term
		);
		$this->filters = $filters;
		wfProfileOut( __METHOD__ . '-other-filters' );
		wfProfileIn( __METHOD__ . '-find-phrase-queries-and-escape' );
		$query = array();
		$matches = array();
		$offset = 0;
		while ( preg_match( '/(?<main>"([^"]+)"(?:~[0-9]+)?)(?<fuzzy>~)?/',
				$term, $matches, PREG_OFFSET_CAPTURE, $offset ) ) {
			$startOffset = $matches[ 0 ][ 1 ];
			if ( $startOffset > $offset ) {
				$query[] = self::fixupQueryStringPart( substr( $term, $offset, $startOffset - $offset ) );
			}

			$main = self::fixupQueryStringPart( $matches[ 'main' ][ 0 ] );
			if ( isset( $matches[ 'fuzzy' ] ) ) {
				$query[] = $main;
			} else {
				$main = $main;
				$exact = join( ' OR ', self::buildFullTextSearchFields( $showRedirects, ".plain:$main" ) );
				$query[] = "($exact)";
			}
			$offset = $startOffset + strlen( $matches[ 0 ][ 0 ] );
		}
		if ( $offset < strlen( $term ) ) {
			$query[] = self::fixupQueryStringPart( substr( $term, $offset ) );
		}
		wfProfileOut( __METHOD__ . '-find-phrase-queries-and-escape' );

		// Actual text query
		if ( count( $query ) > 0 ) {
			wfProfileIn( __METHOD__ . '-build-query' );
			$queryStringQueryString = self::fixupWholeQueryString( implode( ' ', $query ) );
			$fields = self::buildFullTextSearchFields( $showRedirects );
			$this->query = $this->buildSearchTextQuery( $fields, $queryStringQueryString );

			// Only do a phrase match rescore if the query doesn't include any phrases
			if ( $wgCirrusSearchPhraseRescoreBoost > 1.0 && strpos( $queryStringQueryString, '"' ) === false ) {
				$this->rescore = array(
					'window_size' => $wgCirrusSearchPhraseRescoreWindowSize,
					'query' => array(
						'rescore_query' => $this->buildSearchTextQuery( $fields, '"' . $queryStringQueryString . '"' ),
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
					$result = CirrusSearchConnection::getPageType( $indexType )
						->search( $query, $queryOptions );
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
	 * Make sure the the query string part is well formed by escaping some syntax that we don't
	 * want users to get direct access to and making sure quotes are balanced.
	 * These special characters _aren't_ escaped:
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
	public static function fixupQueryStringPart( $string ) {
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
		return $string;
	}

	/**
	 * Make sure that all operators and lucene syntax is used correctly in the query string.
	 * If it isn't then the syntax escaped so it becomes part of the query text.
	 */
	public static function fixupWholeQueryString( $string ) {
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
