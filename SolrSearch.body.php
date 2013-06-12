<?php

class SolrSearch extends SearchEngine {
	private static $client = null;

	/**
	 * Fetch the Solr client.
	 */
	static function getClient() {
		if ( self::$client != null ) {
			return self::$client;
		}
		global $wgSolrSearchServers, $wgSolrSearchMaxRetries;

		self::$client = new Solarium_Client();
		self::$client->setAdapter( 'Solarium_Client_Adapter_Curl' );
	
		// Setup the load balancer
		$loadBalancer = self::$client->getPlugin( 'loadbalancer' );

		// Allow updates to be load balancer just like creates
		$loadBalancer->clearBlockedQueryTypes();

		// Setup failover
		if ( $wgSolrSearchMaxRetries > 1 ) { 
			$loadBalancer->setFailoverEnabled( true );
			$loadBalancer->setFailoverMaxRetries( $wgSolrSearchMaxRetries );
		}

		// Setup the Solr endpoints
		foreach ( $wgSolrSearchServers as $server ) {
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
		$query->setQuery( 'titlePrefix:' . urlencode( $search ) );

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

		// Actual text query
		$query->setQuery( 'text:' . urlencode( $term ) );

		// Perform the search and return a result set
		return new SolrSearchResultSet( $client->select( $query ) );
	}
}

class SolrSearchResultSet extends SearchResultSet {
	private $docs, $hits, $totalHits;

	public function __construct( $res ) {
		$this->docs = $res->getDocuments();
		$this->hits = $res->count();
		$this->totalHits = $res->getNumFound();
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
