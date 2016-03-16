<?php

namespace CirrusSearch\Elastica;

use Elastica\Transport\Http;
use MediaWiki\Logger\LoggerFactory;

/**
 * Implements cross-request connection pooling via hhvm's built in
 * curl_init_pooled. To utilize this transport $wgCirrusSearchClusters should
 * be configured as follows:
 *
 *  $wgCirrusSearchClusters = array(
 *    'default' => array(
 *      'transport' => 'CirrusSearch\Elastica\PooledHttp',
 *      'port' => 12345,
 *      'host' => 'my.host.name',
 *      'config' => array( 'pool' => 'cirrus' ),
 *    )
 *  );
 *
 * Additionally hhvm needs the following configuration in its php.ini:
 *
 *  curl.namedPools = cirrus
 *
 * Other optional pool parameters (also in php.ini) and their defaults:
 *
 *  curl.namedPools.cirrus.size = 5
 *  curl.namedPools.cirrus.reuseLimit = 100
 *  curl.namedPools.cirrus.connGetTimeout = 5000
 *
 * For connection pooling to work optimally you will want to configure a pool
 * for each host you connect to. This means using a different pool for each
 * cluster, and using some sort of load balancer that allows to connect to
 * your entire cluster using a single ip or domain name.
 */
class PooledHttp extends Http {

	/**
	 * Map from pool name to active connection
	 */
	private $_curlPoolConnections = array();

	/**
	 * @param bool $persistent
	 * @return resource Curl handle
	 */
	protected function _getConnection( $persistent = true ) {
		$conn = $this->getConnection();
		if ( !$persistent ) {
			return parent::_getConnection( $persistent );
		}

		$ch = null;
		if ( !$conn->hasConfig( 'pool' ) ) {
			LoggerFactory::getInstance( 'CirrusSearch' )->warning(
				"Elastica connection pool cannot init: missing pool name in connection config"
			);
		} elseif ( !function_exists( 'curl_init_pooled' ) ) {
			LoggerFactory::getInstance( 'CirrusSearch' )->warning(
				"Elastica connection pool cannot init: missing curl_init_pooled method. Are you using hhvm >= 3.9.0?"
			);
		} else {
			$pool = $conn->getConfig( 'pool' );
			// Note that if the connection pool is full this will block
			// up to curl.namedPools.$pool.connGetTimeout ms, defaulting
			// to 5000. If the timeout is reached hhvm will raise a fatal
			// error.
			$ch = curl_init_pooled( $pool );
			if ( $ch === null ) {
				LoggerFactory::getInstance( 'CirrusSearch' )->warning(
					"Elastic connection pool cannot init: Unknown pool {pool}. Did you configure curl.namedPools?",
					array( 'pool' => $pool )
				);
			}
		}

		if ( $ch === null ) {
			return parent::_getConnection( $persistent );
		}

		return $ch;
	}
}
