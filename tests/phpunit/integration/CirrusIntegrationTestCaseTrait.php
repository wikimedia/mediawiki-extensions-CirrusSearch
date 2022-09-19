<?php

namespace CirrusSearch;

use MediaWiki\MediaWikiServices;

trait CirrusIntegrationTestCaseTrait {
	/**
	 * @before
	 */
	final public function cirrusSetUp() {
		$services = MediaWikiServices::getInstance();
		$services->resetServiceForTesting( InterwikiResolver::SERVICE );
		$config = $services->getConfigFactory()->makeConfig( 'CirrusSearch' );
		$config->clearCachesForTesting();
		if ( $config->has( 'CirrusSearchServers' ) ) {
			// Various tests expect to be able to set $wgCirrusSearchClusters and have it
			// work, but setting wgCirrusSearchServers short-circuits the entire cluster
			// operations.
			$this->fail( 'Integration tests require $wgCirrusSeachServers to be unset' );
		}
	}
}
