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
	/**
	 * Singleton instance of the client
	 *
	 * @var Solarium_Client
	 */
	private static $client = null;

	/**
	 * Fetch the Solr client.
	 *
	 * @return Solarium_Client
	 */
	static function getClient() {
		if ( self::$client != null ) {
			return self::$client;
		}
		global $wgCirrusSearchServers, $wgCirrusSearchMaxRetries;

		self::$client = new Solarium_Client();
		self::$client->setAdapter( 'Solarium_Client_Adapter_Curl' );
	
		// Setup the load balancer
		$loadBalancer = self::$client->getPlugin( 'loadbalancer' );

		// Allow updates to be load balancer just like creates
		$loadBalancer->clearBlockedQueryTypes();

		// Setup failover
		if ( $wgCirrusSearchMaxRetries > 1 ) { 
			$loadBalancer->setFailoverEnabled( true );
			$loadBalancer->setFailoverMaxRetries( $wgCirrusSearchMaxRetries );
		}

		// Setup the Solr endpoints
		foreach ( $wgCirrusSearchServers as $server ) {
			$serverConfig = array( 
				'host' => $server,
				'core' => wfWikiId()
			);
			$loadBalancer->addServer( $server, $serverConfig, 1 );
		}
		return self::$client;
	}

	public static function prefixSearch( $ns, $search, $limit, &$results ) {
		// Boilerplate
		$client = self::getClient();
		$query = $client->createSelect();

		// Query params
		$query->setRows( $limit );
		wfDebugLog( 'CirrusSearch', "Prefix searching:  $search" );
		$query->setQuery( 'titlePrefix:%T1%', array( $search  ) );

		// Perform the search
		try {
			$res = $client->select( $query );
		} catch ( Solarium_Exception $e ) {
			wfLogWarning( "Search backend error during title prefix search for '$search'." );
			return false;
		}

		// We only care about title results
		foreach( $res as $r ) {
			$results[] = $r->title;
		}

		return false;
	}

	public function searchText( $term ) {
		$originalTerm = $term;
		function addHighlighting( $highlighting, $term ) {
			if ( $highlighting->getQuery() !== null ) {
				$term = $highlighting->getQuery() . ' OR ' . $term;
			}
			$highlighting->setQuery( $term );
		}

		// Ignore leading ~ because it is used to force displaying search results but not to effect them
		if ( substr( $term, 0, 1 ) === '~' )  {
			$term = substr( $term, 1 );
		}

		// Boilerplate
		$client = self::getClient();
		$query = $client->createSelect();
		$query->setFields( array( 'id', 'title' ) );

		// Offset/limit
		if( $this->offset ) {
			$query->setStart( $this->offset );
		}
		if( $this->limit ) {
			$query->setRows( $this->limit );
		}

		$dismax = $query->getDismax();
		$dismax->setQueryParser( 'edismax' );
		$dismax->setPhraseFields( 'title^1000.0 text^1000.0' );
		$dismax->setPhraseSlop( '3' );
		$dismax->setQueryFields( 'title^20.0 text^3.0' );

		$highlighting = $query->getHighlighting();

		$term = preg_replace_callback(
			'/(?<key>[^ ]+):(?<value>(?:"[^"]+")|(?:[^ ]+)) ?/',
			function ( $matches ) use ( $query, $highlighting ) {
				$key = $matches['key'];
				$value = trim( $matches['value'], '"' );
				switch ( $key ) {
					case 'incategory':
						$query->createFilterQuery( "$key:$value" )->setQuery( '+category:%P1%', array( $value ) );
						return '';
					case 'prefix':
						$query->createFilterQuery( "$key:$value" )->setQuery( '+titlePrefix:%P1% OR +textPrefix:%P1%', array( $value ) );
						addHighlighting( $highlighting, "$value*" );
						return '';
					case 'intitle':
						$query->createFilterQuery( "$key:$value" )->setQuery( '+title:%P1%', array( $value ) );
						addHighlighting( $highlighting, $value );
						return '';
					default:
						return $matches[0];
				}
			},
			$term
		);

		if ( $this->namespaces !== null ) {
			$query->createFilterQuery( 'namspaces' )->setQuery( '+namespace:(' . implode( ' OR ', $this->namespaces ) . ')' );
		}

		// Escape some special characters that we don't want users to pass to solr directly.
		// Some special characters (notable *) are acceptable.
		$term = preg_replace ( '/(\+|-|&&|\|\||!|\(|\)|\{|}|\[|]|\^|"|~|\?|:|\\\)/', '\\\$1', $term );

		// Actual text query
		if ( trim( $term ) === '' ) {
			$term = '*:*';
		} else {
			$spellCheck = $query->getSpellCheck()->setQuery( $term );
			addHighlighting( $highlighting, $term );
		}
		wfDebugLog( 'CirrusSearch', "Searching:  $term" );
		$query->setQuery( $term );

		// Perform the search and return a result set
		try {
			return new CirrusSearchResultSet( $client->select( $query ) );
		} catch ( Solarium_Exception $e ) {
			$status = new Status();
			$status->warning( 'cirrussearch-backend-error' );
			wfLogWarning( "Search backend error during full text search for '$originalTerm'." );
			return $status;
		}
	}

	public function update( $id, $title, $text ) {
		CirrusSearchUpdater::updateRevisions( array( array(
			'rev' => Revision::loadFromPageId( wfGetDB( DB_SLAVE ), $id ),
			'text' => $text
		) ) );
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
					global $wgParser;
					$text = $wgParser->preprocess(
						$c->getTextForSearchIndex(), $t, new ParserOptions() );
					break;
				default:
					break;
			}
		}
		return SearchUpdate::updateText( $text );
	}
}

/**
 * A set of results for Solr
 */
class CirrusSearchResultSet extends SearchResultSet {
	private $result, $docs, $hits, $totalHits, $suggestionQuery, $suggestionSnippet;

	public function __construct( $res ) {
		$this->result = $res;
		$this->docs = $res->getDocuments();
		$this->hits = $res->count();
		$this->totalHits = $res->getNumFound();
		$spellcheck = $res->getSpellcheck();
		$this->suggestionQuery = null;
		$this->suggestionSnippet = null;
		if ( $spellcheck !== null && !$spellcheck->getCorrectlySpelled()  ) {
			$collation = $spellcheck->getCollation();
			if ( $collation !== null ) {
				$this->suggestionQuery = $collation->getQuery();
				$keys = array();
				$highlightedKeys = array();
				foreach ( $collation->getCorrections() as $misspelling => $correction ) {
					// Oddly Solr will sometimes claim that a word is misspelled and then not provide a better spelling for it.
					if ( $misspelling === $correction ) {
						continue;
					}
					// TODO escaping danger
					$keys[] = "/$correction/";
					$highlightedKeys[] = "<em>$correction</em>";
				}
				$this->suggestionSnippet = preg_replace( $keys, $highlightedKeys, $this->suggestionQuery );
			}
		}
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
		static $pos = 0;
		$solrResult = null;
		if( isset( $this->docs[$pos] ) ) {
			$solrResult = new CirrusSearchResult( $this->result, $this->docs[$pos] );
			$pos++;
		}
		return $solrResult;
	}
}

/**
 * An individual search result for Solr
 */
class CirrusSearchResult extends SearchResult {
	private $titleSnippet, $textSnippet;

	public function __construct( $result, $doc ) {
		$fields = $doc->getFields();
		$highlighting = $result->getHighlighting()->getResult( $fields[ 'id' ] )->getFields();

		$this->initFromTitle( Title::newFromText( $fields[ 'title' ] ) );
		if ( isset( $highlighting[ 'title' ] ) ) {
			$this->titleSnippet = $highlighting[ 'title' ][ 0 ];
		} else {
			$this->titleSnippet = '';
		}
		if ( isset( $highlighting[ 'text' ] ) ) {
			$this->textSnippet = $highlighting[ 'text' ][ 0 ];
		} else {
			list( $contextLines, $contextChars ) = SearchEngine::userHighlightPrefs();
			$this->initText();
			// This is kind of lame because it only is nice for space delimited languages
			$matches = array();
			preg_match( "/^((?:.|\n){0,$contextChars})[\\s\\.\n]?/", $this->mText, $matches );
			$this->textSnippet = implode( "\n", array_slice( explode( "\n", $matches[1] ), 0, $contextLines ) );
		}
	}

	public function getTitleSnippet( $terms ) {
		return $this->titleSnippet;
	}

	public function getTextSnippet( $terms ) {
		return $this->textSnippet;
	}
}
