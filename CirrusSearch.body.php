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
		wfDebugLog( 'CirrusSearch', 'Searching:  ' . $search );
		$query->setQuery( 'titlePrefix:' . $search );

		// Perform the search
		$res = $client->select( $query );

		// We only care about title results
		foreach( $res as $r ) {
			$results[] = $r->title;
		}

		return false;
	}

	public function searchText( $term ) {
		try {
			return $this->searchTextInernal( $term );
		} catch (CirrusSearchInvalidColonPrefixInQueryException $e) {
			$status = new Status();
			$status->warning( 'cirrussearch-unknown-colon-keyword-in-query', $e->getKeyword() );
			return $status;
		}
	}

	private function searchTextInernal( $term ) {
		// Boilerplate
		$client = self::getClient();
		$query = $client->createSelect();

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

		$term = preg_replace_callback(
			'/(?<key>[^ ]+):(?<value>(?:"[^"]+")|(?:[^ ]+)) ?/',
			function ( $matches ) use ( $query ) {
				$key = $matches['key'];
				$value = $matches['value'];
				switch ( $key ) {
					case 'incategory':
						$filter = $query->createFilterQuery("$key:$value");
						$filter->setQuery( "category:$value" );
						break;
					default:
						throw new CirrusSearchInvalidColonPrefixInQueryException( $key );
				}
				return '';
			},
			$term
		);

		foreach ( $this->namespaces as $namespace ) {
			$filter = $query->createFilterQuery("namespace:$namespace");
			$filter->setQuery( 'namespace:%P1%', array( $namespace ) );
		}

		// Build spellcheck after removing all special commands from the query
		$spellCheck = $query->getSpellCheck();
		$spellCheck->setQuery( $term );

		// Actual text query
		wfDebugLog( 'CirrusSearch', "Searching:  $term" );
		$query->setQuery( $term );

		// Perform the search and return a result set
		return new CirrusSearchResultSet( $client->select( $query ) );
	}
}

class CirrusSearchResultSet extends SearchResultSet {
	private $docs, $hits, $totalHits, $suggestionQuery, $suggestionSnippet;

	public function __construct( $res ) {
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
			$doc = $this->docs[$pos];
			$fields = $doc->getFields();
			$solrResult = SearchResult::newFromTitle(
				Title::newFromText( $fields['title'] ) );
			$pos++;
		}
		return $solrResult;
	}
}


/**
 * Thrown when there is an error in the user's query.
 */
class CirrusSearchInvalidColonPrefixInQueryException extends Exception {
	private $keyword;

	public function __construct( $keyword ) {
        $this->keyword = $keyword;
        parent::__construct( "Unknown colon keyword:  $this->keyword" );
    }

    public function getKeyword() {
    	return $this->keyword;
    }
}