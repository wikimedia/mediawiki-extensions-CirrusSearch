<?php

class CirrusSearch extends SearchEngine {
	private static $client = null;

	/**
	 * Fetch the Solr client.
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
		$res = $client->select( $query );

		// We only care about title results
		foreach( $res as $r ) {
			$results[] = $r->title;
		}

		return false;
	}

	public function searchText( $term ) {
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
		$highlighting->setFields( array( 'title', 'text' ) );

		$term = preg_replace_callback(
			'/(?<key>[^ ]+):(?<value>(?:"[^"]+")|(?:[^ ]+)) ?/',
			function ( $matches ) use ( $query ) {
				$key = $matches['key'];
				$value = trim( $matches['value'], '"' );
				switch ( $key ) {
					case 'incategory':
						$filter = $query->createFilterQuery("$key:$value");
						$filter->setQuery( '+category:%P1%', array( $value ) );
						break;
					case 'prefix':
						$filter = $query->createFilterQuery("$key:$value");
						$filter->setQuery( '+titlePrefix:%P1% OR +textPrefix:%P1%', array( $value ) );
						$query->getHighlighting()->setQuery( $value . '*' );
						break;
					default:
						return $matches[0];
				}
				return '';
			},
			$term
		);

		foreach ( $this->namespaces as $namespace ) {
			$filter = $query->createFilterQuery("namespace:$namespace");
			$filter->setQuery( '+namespace:%T1%', array( $namespace ) );
		}

		// Actual text query
		if ( trim( $term ) === '' ) {
			$term = '*:*';
		} else {
			$spellCheck = $query->getSpellCheck()->setQuery( $term );
			if ( $highlighting->getQuery() !== null ) {
				$highlighting->setQuery( $highlighting->getQuery() . ' OR ' . $term );
			}
		}
		wfDebugLog( 'CirrusSearch', "Searching:  $term" );
		$query->setQuery( $term );

		// Perform the search and return a result set
		return new CirrusSearchResultSet( $client->select( $query ) );
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
}

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
