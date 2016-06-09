<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\Connection;
use CirrusSearch\SearchConfig;
use MediaWiki\MediaWikiServices;

/**
 * Cirrus helpful extensions to Maintenance.
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
abstract class Maintenance extends \Maintenance {
	/**
	 * @var string The string to indent output with
	 */
	protected static $indent = null;

	/**
	 * @var Connection|null
	 */
	private $connection;

	public function __construct() {
		parent::__construct();
		$this->addOption( 'cluster', 'Perform all actions on the specified elasticsearch cluster', false, true );
	}

	/**
	 * @param string|null $cluster
	 * @return Connection
	 */
	public function getConnection( $cluster = null ) {
		if( $cluster ) {
			$config = MediaWikiServices::getInstance()
				->getConfigFactory()
				->makeConfig( 'CirrusSearch' );
			if ( $config instanceof SearchConfig ) {
				if (!$config->getElement( 'CirrusSearchClusters', $cluster ) ) {
					$this->error( 'Unknown cluster.', 1 );
				}
				return Connection::getPool( $config, $cluster );
			} else {
				// We shouldn't ever get here ... but the makeConfig type signature returns the parent class of SearchConfig
				// so just being extra careful...
				throw new \RuntimeException( 'Expected instanceof CirrusSearch\SearchConfig, but received ' . get_class( $config ) );
			}
		}
		if ( $this->connection === null ) {
			$config = MediaWikiServices::getInstance()
				->getConfigFactory()
				->makeConfig( 'CirrusSearch' );
			$cluster = $this->decideCluster( $config );
			$this->connection = Connection::getPool( $config, $cluster );
		}
		return $this->connection;
	}

	/**
	 * @param SearchConfig $config
	 * @return string|null
	 */
	private function decideCluster( SearchConfig $config ) {
		$cluster = $this->getOption( 'cluster', null );
		if ( $cluster === null ) {
			return null;
		}
		if ( $config->has( 'CirrusSearchServers' ) ) {
			$this->error( 'Not configured for cluster operations.', 1 );
		}
		$hosts = $config->getElement( 'CirrusSearchClusters', $cluster );
		if ( $hosts === null ) {
			$this->error( 'Unknown cluster.', 1 );
		}
		return $cluster;
	}

	/**
	 * Execute a callback function at the end of initialisation
	 */
	public function loadSpecialVars() {
		parent::loadSpecialVars();
		if ( Maintenance::$indent === null ) {
			// First script gets no indentation
			Maintenance::$indent = '';
		} else {
			// Others get one tab beyond the last
			Maintenance::$indent = Maintenance::$indent . "\t";
		}
	}

	/**
	 * Call to signal that execution of this maintenance script is complete so
	 * the next one gets the right indentation.
	 */
	public function done() {
		Maintenance::$indent = substr( Maintenance::$indent, 1 );
	}

	/**
	 * @param string $message
	 * @param string|null $channel
	 */
	public function output( $message, $channel = null ) {
		parent::output( $message );
	}

	public function outputIndented( $message ) {
		$this->output( Maintenance::$indent . $message );
	}

	/**
	 * @param string $err
	 * @param int $die
	 */
	public function error( $err, $die = 0 ) {
		parent::error( $err, $die );
	}

	/**
	 * Disable all pool counters and cirrus query logs.
	 * Only useful for maint scripts
	 *
	 * Ideally this method could be run in the constructor
	 * but apparently globals are reset just before the
	 * call to execute()
	 */
	protected function disablePoolCountersAndLogging() {
		global $wgPoolCounterConf, $wgCirrusSearchLogElasticRequests;

		// Make sure we don't flood the pool counter
		$wgPoolCounterConf = array();
		unset( $wgPoolCounterConf['CirrusSearch-Search'],
			$wgPoolCounterConf['CirrusSearch-PerUser'] );

		// Don't skew the dashboards by logging these requests to
		// the global request log.
		$wgCirrusSearchLogElasticRequests = false;
	}
}
