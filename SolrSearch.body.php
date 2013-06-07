<?php

class SolrSearch {
	private static $client = null;

	/**
	 * Fetch the Solr client.
	 */
	static function getClient() {
		if ( self::$client != null ) {
			return self::$client;
		}
		global $wgSolrSearchServers, $wgSolrSearchMaxRetries, $wgSitename;

		self::$client = new Solarium_Client();
		self::$client->setAdapter('Solarium_Client_Adapter_Curl');
		
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
		foreach ( preg_split( '/, ?/', $wgSolrSearchServers ) as $server ) {
			$serverConfig = array( 
				'host' => $server,
				'core' => $wgSitename
			);
			$loadBalancer->addServer( $server, $serverConfig, 1 );
		}

		return self::$client;
	}


}
