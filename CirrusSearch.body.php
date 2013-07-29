<?php
/**
 * Implementation of core search features in Solr
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
class CirrusSearch extends SearchEngine {
	const PHRASE_TITLE = 'phrase_title';
	const PHRASE_TEXT = 'phrase_text';
	const HIGHLIGHT_PRE = '<span class="searchmatch">';
	const HIGHLIGHT_POST = '</span>';
	const PAGE_TYPE_NAME = 'page';
	/**
	 * Maximum title length that we'll check in prefix search.  Since titles can
	 * be 255 bytes in length we're setting this to 255 characters but this
	 * might cause bloat in the title's prefix index so we'll have to keep an
	 * eye on this.
	 */
	const MAX_PREFIX_SEARCH = 255;

	/**
	 * Singleton instance of the client
	 *
	 * @var \Elastica\Client
	 */
	private static $client = null;

	/**
	 * Article IDs updated in this process.  Used for deduplication of updates.
	 *
	 * @var array(Integer)
	 */
	private static $updated = array();

	public static function getClient() {
		if ( self::$client != null ) {
			return self::$client;
		}
		global $wgCirrusSearchServers, $wgCirrusSearchMaxRetries;

		// Setup the Elastica endpoints
		$servers = array();
		foreach ( $wgCirrusSearchServers as $server ) {
			$servers[] = array('host' => $server);
		}
		self::$client = new \Elastica\Client( array(
			'servers' => $servers
		) );
		return self::$client;
	}

	/**
	 * Fetch the Elastica Index.
	 *
	 * @param mixed $identifier if specified get the named identified version of the index
	 * @return \Elastica\Index
	 */
	public static function getIndex( $identifier = false ) {
		return CirrusSearch::getClient()->getIndex( CirrusSearch::getIndexName( $identifier ) );
	}

	/**
	 * Get the name of the index.
	 * @param mixed $identifier if specified get the named identifier of the index
	 * @return string name index should have considering $identifier
	 */
	public static function getIndexName( $identifier = false ) {
		$name = wfWikiId();
		if ( $identifier ) {
			$name = $name . '_' . $identifier;
		}
		return $name;
	}

	/**
	 * Fetch the Elastica Type for pages.
	 *
	 * @ \Elastica\Type
	 */
	static function getPageType() {
		return CirrusSearch::getIndex()->getType( CirrusSearch::PAGE_TYPE_NAME );
	}

	public static function prefixSearch( $ns, $search, $limit, &$results ) {
		wfDebugLog( 'CirrusSearch', "Prefix searching:  $search" );
		// Boilerplate
		$nsNames = RequestContext::getMain()->getLanguage()->getNamespaces();
		$query = new Elastica\Query();
		$query->setFields( array( 'id', 'title', 'namespace' ) );

		// Query params
		$query->setLimit( $limit );
		$query->setFilter( CirrusSearch::buildNamespaceFilter( $ns ) );
		$match = new \Elastica\Query\Match();
		$match->setField( 'title.prefix', array(
			'query' => substr( $search, 0, CirrusSearch::MAX_PREFIX_SEARCH ),
			'analyzer' => 'prefix_query'
		) );
		$query->setQuery( $match );

		// Perform the search
		$work = new PoolCounterWorkViaCallback( 'CirrusSearch-Search', "_elasticsearch", array(
			'doWork' => function() use ( $search, $query ) {
				try {
					$result = CirrusSearch::getPageType()->search( $query );
					wfDebugLog( 'CirrusSearch', 'Search completed in ' . $result->getTotalTime() . ' millis' );
					return $result;
				} catch ( \Elastica\Exception\ExceptionInterface $e ) {
					wfLogWarning( "Search backend error during title prefix search for '$search'." );
					return false;
				}
			}
		) );
		$result = $work->execute();
		if ( !$result ) {
			return false;
		}

		// We only care about title results
		foreach( $result->getResults() as $r ) {
			$results[] = Title::makeTitle( $r->namespace, $r->title )->getPrefixedText();
		}

		return false;
	}

	public function searchText( $term ) {
		wfDebugLog( 'CirrusSearch', "Searching:  $term" );
		global $wgCirrusSearchPhraseSuggestMaxErrors;
		
		$originalTerm = $term;

		// Ignore leading ~ because it is used to force displaying search results but not to effect them
		if ( substr( $term, 0, 1 ) === '~' )  {
			$term = substr( $term, 1 );
		}

		$query = new Elastica\Query();
		$query->setFields( array( 'id', 'title', 'namespace', 'redirect' ) );

		$filters = array();

		// Offset/limit
		if( $this->offset ) {
			$query->setFrom( $this->offset );
		}
		if( $this->limit ) {
			$query->setLimit( $this->limit );
		}
		$filters[] = CirrusSearch::buildNamespaceFilter( $this->namespaces );
		$extraQueryStrings = array();

		// Transform Mediawiki specific syntax to filters and extra (pre-escaped) query string
		$term = preg_replace_callback(
			'/(?<key>[^ ]+):(?<value>(?:"[^"]+")|(?:[^ ]+)) ?/',
			function ( $matches ) use ( &$filters, &$extraQueryStrings ) {
				$key = $matches['key'];
				$value = trim( $matches['value'], '"' );
				switch ( $key ) {
					case 'incategory':
						$filters[] = new \Elastica\Filter\Query( new \Elastica\Query\Field(
							'category', CirrusSearch::fixupQueryString( $value ) ) );
						return '';
					case 'prefix':
						return "$value*";
					case 'intitle':
						$extraQueryStrings[] = 'title:' . CirrusSearch::fixupQueryString( $value );
						return '';
					default:
						return $matches[0];
				}
			},
			$term
		);

		// This seems out of the style of the rest of the Elastica....
		$query->setHighlight( array( 
			'pre_tags' => array( CirrusSearch::HIGHLIGHT_PRE ),
			'post_tags' => array( CirrusSearch::HIGHLIGHT_POST ),
			'fields' => array(
				'title' => array( 'number_of_fragments' => 0 ), // Don't fragment the title - it is too small.
				'text' => array( 'number_of_fragments' => 1 ),
				'redirect.title' => array( 'number_of_fragments' => 0 ), // The redirect field is just like the title field.
			)
		) );

		$filters = array_filter( $filters ); // Remove nulls from $fitlers
		if ( count( $filters ) > 1 ) {
			$mainFilter = new \Elastica\Filter\Bool();
			foreach ( $filters as $filter ) {
				$mainFilter->addMust( $filter );
			}
			$query->setFilter( $mainFilter );
		} else if ( count( $filters ) === 1 ) {
			$query->setFilter( $filters[0] );
		}

		// Actual text query
		if ( trim( $term ) !== '' || $extraQueryStrings ) {
			$queryStringQueryString = trim( implode( ' ', $extraQueryStrings ) . ' ' . CirrusSearch::fixupQueryString( $term ) );
			$queryStringQuery = new \Elastica\Query\QueryString( $queryStringQueryString );
			$fields = array( 'title^20.0', 'text^3.0' );
			if ( $this->showRedirects ) {
				$fields[] = 'redirect.title^15.0';
			}
			$queryStringQuery->setFields( $fields );
			$queryStringQuery->setAutoGeneratePhraseQueries( true );
			$queryStringQuery->setPhraseSlop( 3 );
			// TODO phrase match boosts?
			$query->setQuery( $queryStringQuery );
			$query->setParam( 'suggest', array(
				'text' => $term,
				CirrusSearch::PHRASE_TITLE => array(
					'phrase' => array(
						'field' => 'title.suggest',
						'max_errors' => $wgCirrusSearchPhraseSuggestMaxErrors
					)
				),
				// TODO redirects here too?
				CirrusSearch::PHRASE_TEXT => array(
					'phrase' => array(
						'field' => 'text.suggest',
						'max_errors' => $wgCirrusSearchPhraseSuggestMaxErrors
					)
				)
			));
		}

		// Perform the search
		$work = new PoolCounterWorkViaCallback( 'CirrusSearch-Search', "_elasticsearch", array(
			'doWork' => function() use ( $originalTerm, $query ) {
				try {
					$result = CirrusSearch::getPageType()->search( $query );
					wfDebugLog( 'CirrusSearch', 'Search completed in ' . $result->getTotalTime() . ' millis' );
					return $result;
				} catch ( \Elastica\Exception\ExceptionInterface $e ) {
					wfLogWarning( "Search backend error during full text search for '$originalTerm'.  " .
						"Error message is:  " . $e->getMessage() );
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
		return new CirrusSearchResultSet( $result );
	}

	/**
	 * Filter a query to only return results in given namespace(s)
	 *
	 * @param array $ns Array of namespaces
	 */
	private static function buildNamespaceFilter( array $ns ) {
		if ( $ns !== null && count( $ns ) ) {
			return new \Elastica\Filter\Terms( 'namespace', $ns );
		}
		return null;
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
		$string = preg_replace( '/(\+|-|&&|\|\||!|\(|\)|\{|}|\[|]|\^|\?|:|\\\)/', '\\\$1', $string );
		if ( !preg_match( '/^(
				[^"]| 			(?# non quoted terms)
				"([^"]|\\.)*" 	(?# quoted terms)
			)*$/x', $string ) ) {
			$string = $string . '"';
		}

		return $string;
	}

	public function update( $id, $title, $text ) {
		$revision = Revision::loadFromPageId( wfGetDB( DB_SLAVE ), $id );
		$content = $revision->getContent();
		if ( in_array( $id, CirrusSearch::$updated ) ) {
			// Already indexed $id
			return;
		}
		if ( $content->isRedirect() ) {
			$target = $content->getUltimateRedirectTarget();
			wfDebugLog( 'CirrusSearch', "Updating search index for $title which is a redirect to " . $target->getText() );
			$targetRevision = Revision::loadFromPageId( wfGetDB( DB_SLAVE ), $target->getArticleID() );
			$newUpdate = new SearchUpdate( $target->getArticleID(), $target, $targetRevision->getContent() );
			$newUpdate->doUpdate();
		} else {
			// Technically this is supposed to be just a title update but that is more complicated then
			// just rebuilding the text.  It doesn't look like these title updates are used frequently
			// so we'll just go with the simple implementation here.
			if ( $text === null ) {
				$text = $this->getTextFromContent( $revision->getTitle(), $content );
			}
			CirrusSearchUpdater::updateRevisions( array( array(
				'rev' => $revision,
				'text' => $text
			) ) );
			CirrusSearch::$updated[] = $id;
		}
	}

	public static function linksUpdateCompletedHook( $linkUpdate ) {
		$title = $linkUpdate->getTitle();
		$articleId = $title->getArticleID();
		$revision = Revision::loadFromPageId( wfGetDB( DB_SLAVE ), $articleId );
		$update = new SearchUpdate( $articleId, $title, $revision->getContent() );
		$update->doUpdate();
	}

	public function updateTitle( $id, $title ) {
		$this->update( $id, $title, null );
	}

	public function delete( $id, $title ) {
		CirrusSearchUpdater::deletePages( array( $id ) );
	}

	public function getTextFromContent( Title $t, Content $c = null ) {
		$text = parent::getTextFromContent( $t, $c );
		if( $c ) {
			switch ( $c->getModel() ) {
				case CONTENT_MODEL_WIKITEXT:
					$article = new Article( $t, 0 );
					$text = $article->getParserOutput()->getText();
					break;
				default:
					$text = SearchUpdate::updateText( $text );
					break;
			}
		}
		return $text;
	}
}

/**
 * A set of results for Solr
 */
class CirrusSearchResultSet extends SearchResultSet {
	private $result, $docs, $hits, $totalHits, $suggestionQuery, $suggestionSnippet;

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
		foreach ( $suggest[ CirrusSearch::PHRASE_TITLE ][ 0 ][ 'options' ] as $option ) {
			return $option[ 'text' ];
		}
		foreach ( $suggest[ CirrusSearch::PHRASE_TEXT ][ 0 ][ 'options' ] as $option ) {
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
 * An individual search result for Solr
 */
class CirrusSearchResult extends SearchResult {
	private $titleSnippet, $redirectTitle, $redirectSnipppet, $textSnippet;

	public function __construct( $result ) {
		$title = Title::makeTitle( $result->namespace, $result->title );
		$this->initFromTitle( $title );
		$highlights = $result->getHighlights();
		if ( isset( $highlights[ 'title' ] ) ) {
			$nstext = '';
			if ( $title->getNamespace() !== 0 ) {
				$nstext = $title->getNsText() . ':';
			}
			$this->titleSnippet = $nstext . $highlights[ 'title' ][ 0 ];
		} else {
			$this->titleSnippet = '';
		}
		if ( !isset( $highlights[ 'title' ] ) && isset( $highlights[ 'redirect.title' ] ) ) {
			$this->redirectSnipppet = $highlights[ 'redirect.title' ][ 0 ];
			$this->redirectTitle = $this->findRedirectTitle( $result->redirect );
		} else {
			$this->redirectSnipppet = '';
			$this->redirectTitle = null;
		}
		if ( isset( $highlights[ 'text' ] ) ) {
			$this->textSnippet = $highlights[ 'text' ][ 0 ];
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
	}

	/**
	 * Build the redirect title from the highlighted redirect snippet.
	 * @param array $redirects Array of redirects stored as arrays with 'title' and 'namespace' keys
	 * @return Title object representing the redirect
	 */
	private function findRedirectTitle( $redirects ) {
		$title = str_replace(
			array( CirrusSearch::HIGHLIGHT_PRE, CirrusSearch::HIGHLIGHT_POST ),
			'',
			$this->redirectSnipppet );
		// Grab the redirect with the lowest namespace.  That is pretty arbitrary but it prioritizes 0 over others.
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
}
