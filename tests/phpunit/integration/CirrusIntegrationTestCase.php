<?php

namespace CirrusSearch;

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

class CirrusIntegrationTestCase extends \MediaWikiIntegrationTestCase {
	use CirrusTestCaseTrait;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		LoggerFactory::getInstance( 'CirrusSearchIntegTest' )->debug( 'Using seed ' . self::getSeed() );
	}

	protected function setUp(): void {
		parent::setUp();
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
