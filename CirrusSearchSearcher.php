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
	const PHRASE_TITLE = 'phrase_title';
	const PHRASE_TEXT = 'phrase_text';
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
		$requestLength = strlen( $search );
		if ( $requestLength > self::MAX_PREFIX_SEARCH ) {
			throw new UsageException( 'Prefix search requset was longer longer than the maximum allowed length.' .
				" ($requestLength > " . self::MAX_PREFIX_SEARCH . ')', 'request_too_long', 400 );
		}
		wfDebugLog( 'CirrusSearch', "Prefix searching:  $search" );

		$match = new \Elastica\Query\Match();
		$match->setField( 'title.prefix', array(
			'query' => substr( $search, 0, self::MAX_PREFIX_SEARCH ),
			'analyzer' => 'prefix_query'  // TODO switch this to lowercase_keyword after the it is fully deployed
		) );
		$this->filters[] = new \Elastica\Filter\Query( $match );
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
		global $wgCirrusSearchWeights;
		global $wgCirrusSearchPhraseSuggestMaxErrors;
		global $wgCirrusSearchPhraseSuggestConfidence;
		wfDebugLog( 'CirrusSearch', "Searching:  $term" );

		// Transform Mediawiki specific syntax to filters and extra (pre-escaped) query string
		$originalTerm = $term;
		$extraQueryStrings = array();
		$filters = $this->filters;
		$term = preg_replace_callback(
			'/(?<key>[^ ]+):(?<value>(?:"[^"]+")|(?:[^ "]+)) ?/',
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
		$this->filters = $filters;

		// Actual text query
		if ( trim( $term ) !== '' || $extraQueryStrings ) {
			$queryStringQueryString = trim( implode( ' ', $extraQueryStrings ) . ' ' . self::fixupQueryString( $term ) );
			$this->query = new \Elastica\Query\QueryString( $queryStringQueryString );
			$fields = array(
				'title^' . $wgCirrusSearchWeights[ 'title' ],
				'heading^' . $wgCirrusSearchWeights[ 'heading' ],
				'text',
			);
			if ( $showRedirects ) {
				$fields[] = 'redirect.title^' . $wgCirrusSearchWeights[ 'redirect' ];
			}
			$this->query->setFields( $fields );
			$this->query->setAutoGeneratePhraseQueries( true );
			$this->query->setPhraseSlop( 3 );
			$this->query->setDefaultOperator( 'AND' );
			// TODO phrase match boosts?
			$this->suggest = array(
				'text' => $term,
				self::PHRASE_TITLE => array(
					'phrase' => array(
						'field' => 'title.suggest',
						'size' => 1,
						'max_errors' => $wgCirrusSearchPhraseSuggestMaxErrors,
						'confidence' => $wgCirrusSearchPhraseSuggestConfidence,
						'direct_generator' => array(
							array(
								'field' => 'title.suggest',
								'suggest_mode' => 'always', // Forces us to generate lots of phrases to try.
							),
						),
					)
				),
				self::PHRASE_TEXT => array(
					'phrase' => array(
						'field' => 'text.suggest',
						'size' => 1,
						'max_errors' => $wgCirrusSearchPhraseSuggestMaxErrors,
						'confidence' => $wgCirrusSearchPhraseSuggestConfidence,
						'direct_generator' => array(
							array(
								'field' => 'text.suggest',
								'suggest_mode' => 'always', // Forces us to generate lots of phrases to try.
							),
						),
					)
				)
			);
		}
		$this->description = "full text search for '$originalTerm'";
		return $this->search();
	}

	/**
	 * @param $id article id to search
	 * @return CirrusSearchResultSet|null|SearchResultSet|Status
	 */
	public function moreLikeThisArticle( $id ) {
		global $wgCirrusSearchMoreLikeThisConfig;

		// It'd be better to be able to have Elasticsearch fetch this during the query rather than make
		// two passes but it doesn't support that at this point
		$indexType = $this->pickIndexTypeFromNamespaces();
		$getWork = new PoolCounterWorkViaCallback( 'CirrusSearch-Search', "_elasticsearch", array(
			'doWork' => function() use ( $id, $indexType ) {
				try {
					$result = CirrusSearchConnection::getPageType( $indexType )->getDocument( $id, array(
						'fields' => array( 'text' ),
					) );
					return $result;
				} catch ( \Elastica\Exception\NotFoundException $e ) {
					// We don't need to log NotFoundExceptions....
					return null;
				} catch ( \Elastica\Exception\ExceptionInterface $e ) {
					wfLogWarning( "Search backend error during get for $id.  Error message is:  " . $e->getMessage() );
					return false;
				}
			}
		) );
		$getResult = $getWork->execute();
		if ( $getResult === null ) {
			// This corresponds to not found exceptions
			return null;
		}
		if ( $getResult === false ) {
			// These are actual errors
			$status = new Status();
			$status->warning( 'cirrussearch-backend-error' );
			return $status;
		}

		$this->query = new \Elastica\Query\MoreLikeThis();
		$this->query->setParams( $wgCirrusSearchMoreLikeThisConfig );
		$this->query->setLikeText( Sanitizer::stripAllTags( $getResult->text ) );
		$this->query->setFields( array( 'text' ) );
		$idFilter = new \Elastica\Filter\Ids();
		$idFilter->addId( $id );
		$this->filters[] = new \Elastica\Filter\BoolNot( $idFilter );

		return $this->search();
	}

	/**
	 * Powers full-text-like searches which means pretty much everything but prefixSearch.
	 * @return CirrusSearchResultSet|null|SearchResultSet|Status
	 */
	private function search() {
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
		return $this->resultsType->transformElasticsearchResult( $result );
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
	 */
	public static function fixupQueryString( $string ) {
		$string = preg_replace( '/(
				\+|
				-|
				\/|		(?# no regex searches allowed)
				&&|
				\|\||
				!|
				\(|
				\)|
				\{|
				}|
				\[|
				]|
				\^|
				\?|
				:|		(?# no specifying your own fields)
				\\\
			)/x', '\\\$1', $string );
		if ( !preg_match( '/^(
				[^"]| 			(?# non quoted terms)
				"([^"]|\\.)*" 	(?# quoted terms)
			)*$/x', $string ) ) {
			$string = $string . '"';
		}
		// Turn bad fuzzy searches into searches that contain a ~
		$string = preg_replace_callback( '/(?<leading>[^\s"])~(?<trailing>\S+)/', function ( $matches ) {
			wfDebugLog( 'CirrusSearch', 'checking fuzzy:' . $matches[0] );
			if ( preg_match( '/0|(?:0?\.[0-9]+)|(?:1(?:\.0)?)/', $matches[ 'trailing' ] ) ) {
				return $matches[ 0 ];
			} else {
				return $matches[ 'leading' ] . '\\~' . $matches[ 'trailing' ];
			}
		}, $string );
		// Turn bad proximity searches into seraches that contain a ~
		$string = preg_replace_callback( '/"~(?<trailing>\S*)/', function ( $matches ) {
			wfDebugLog( 'CirrusSearch', 'checking proximity:' . $matches[0] );
			if ( preg_match( '/[0-9]+/', $matches[ 'trailing' ] ) ) {
				return $matches[ 0 ];
			} else {
				return '"\\~' . $matches[ 'trailing' ];
			}
		}, $string );
		wfDebugLog( 'CirrusSearch', 'Got  ' . $string );
		return $string;
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
		return array( 'id', 'title', 'namespace', 'redirect' );
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
				'redirect.title' => array( 'number_of_fragments' => 1 ),
				'heading' => array( 'number_of_fragments' => 1),
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
	private $result, $hits, $totalHits, $suggestionQuery, $suggestionSnippet;

	public function __construct( $res ) {
		$this->result = $res;
		$this->hits = $res->count();
		$this->totalHits = $res->getTotalHits();
		$this->suggestionQuery = $this->findSuggestionQuery();
		$this->suggestionSnippet = $this->highlightingSuggestionSnippet();
	}

	private function findSuggestionQuery() {
		// TODO some kind of weighting?
		$suggest = $this->result->getResponse()->getData();
		if ( !array_key_exists( 'suggest', $suggest ) ) {
			return null;
		}
		$suggest = $suggest[ 'suggest' ];
		foreach ( $suggest[ CirrusSearchSearcher::PHRASE_TITLE ][ 0 ][ 'options' ] as $option ) {
			return $option[ 'text' ];
		}
		foreach ( $suggest[ CirrusSearchSearcher::PHRASE_TEXT ][ 0 ][ 'options' ] as $option ) {
			return $option[ 'text' ];
		}
		return null;
	}

	private function highlightingSuggestionSnippet() {
		// TODO wrap the corrections in <em>s.... ES doesn't make this easy.
		return $this->suggestionQuery;
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

	public function __construct( $result ) {
		$title = Title::makeTitle( $result->namespace, $result->title );
		$this->initFromTitle( $title );
		$highlights = $result->getHighlights();
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
			list( $contextLines, $contextChars ) = SearchEngine::userHighlightPrefs();
			$this->initText();
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
	 * Escape highlighted text coming back from Elasticsearch.
	 */
	public static function escapeHighlightedText( $text ) {
		if ( self::$highlightPreEscaped === null ) {
			self::$highlightPreEscaped = htmlspecialchars( CirrusSearchSearcher::HIGHLIGHT_PRE );
			self::$highlightPostEscaped = htmlspecialchars( CirrusSearchSearcher::HIGHLIGHT_POST );
		}
		return str_replace( array( self::$highlightPreEscaped, self::$highlightPostEscaped ),
			array( CirrusSearchSearcher::HIGHLIGHT_PRE, CirrusSearchSearcher::HIGHLIGHT_POST ),
			htmlspecialchars( $text ) );
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

	function getSectionTitle() {
		return $this->sectionTitle;
	}
}
