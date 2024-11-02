<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\Connection;
use CirrusSearch\MetaStore\MetaStoreIndex;
use CirrusSearch\SearchConfig;
use CirrusSearch\UserTestingEngine;
use MediaWiki\Maintenance\Maintenance as MWMaintenance;
use MediaWiki\MediaWikiServices;
use MediaWiki\Settings\SettingsBuilder;
use MediaWiki\Status\Status;
use RuntimeException;

// Maintenance class is loaded before autoload, so we need to pull the interface
require_once __DIR__ . '/Printer.php';

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
abstract class Maintenance extends MWMaintenance implements Printer {
	/**
	 * @var string The string to indent output with
	 */
	protected static $indent = null;

	/**
	 * @var Connection|null
	 */
	private $connection;

	/**
	 * @var SearchConfig
	 */
	private $searchConfig;

	public function __construct() {
		parent::__construct();
		$this->addOption( 'cluster', 'Perform all actions on the specified elasticsearch cluster',
			false, true );
		$this->addOption( 'userTestTrigger', 'Use config var and profiles set in the user testing ' .
			'framework, e.g. --userTestTrigger=trigger', false, true );
		$this->requireExtension( 'CirrusSearch' );
	}

	public function finalSetup( SettingsBuilder $settingsBuilder ) {
		parent::finalSetup( $settingsBuilder );

		if ( $this->hasOption( 'userTestTrigger' ) ) {
			$this->setupUserTest();
		}
	}

	/**
	 * Setup config vars with the UserTest framework
	 */
	private function setupUserTest() {
		// Configure the UserTesting framework
		// Useful in case an index needs to be built with a
		// test config that is not meant to be the default.
		// This is realistically only usefull to test across
		// multiple clusters.
		// Perhaps setting $wgCirrusSearchIndexBaseName to an
		// alternate value would testing on the same cluster
		// but this index would not receive updates.
		$trigger = $this->getOption( 'userTestTrigger' );
		$engine = UserTestingEngine::fromConfig( $this->getConfig() );
		$status = $engine->decideTestByTrigger( $trigger );
		if ( !$status->isActive() ) {
			$this->fatalError( "Unknown user test trigger: $trigger" );
		}
		$engine->activateTest( $status );
	}

	/** @inheritDoc */
	public function createChild( string $maintClass, ?string $classFile = null ): MWMaintenance {
		$child = parent::createChild( $maintClass, $classFile );
		if ( $child instanceof self ) {
			$child->searchConfig = $this->searchConfig;
		}

		return $child;
	}

	/**
	 * @param string|null $cluster
	 * @return Connection
	 */
	public function getConnection( $cluster = null ) {
		if ( $cluster ) {
			$connection = Connection::getPool( $this->getSearchConfig(), $cluster );
		} else {
			if ( $this->connection === null ) {
				$cluster = $this->decideCluster();
				$this->connection = Connection::getPool( $this->getSearchConfig(), $cluster );
			}
			$connection = $this->connection;
		}

		$connection->setTimeout( $this->getSearchConfig()->get( 'CirrusSearchMaintenanceTimeout' ) );

		return $connection;
	}

	public function getSearchConfig() {
		if ( $this->searchConfig == null ) {
			$this->searchConfig = MediaWikiServices::getInstance()
				->getConfigFactory()
				->makeConfig( 'CirrusSearch' );
			if ( !$this->searchConfig instanceof SearchConfig ) {
				// We shouldn't ever get here ... but the makeConfig type signature returns the parent
				// class of SearchConfig so just being extra careful...
				throw new \RuntimeException( 'Expected instanceof CirrusSearch\SearchConfig, but received ' .
					get_class( $this->searchConfig ) );
			}
		}
		return $this->searchConfig;
	}

	public function getMetaStore( ?Connection $conn = null ) {
		return new MetaStoreIndex( $conn ?? $this->getConnection(), $this, $this->getSearchConfig() );
	}

	/**
	 * @return string|null
	 */
	private function decideCluster() {
		$cluster = $this->getOption( 'cluster', null );
		if ( $cluster === null ) {
			return null;
		}
		if ( $this->getSearchConfig()->has( 'CirrusSearchServers' ) ) {
			$this->fatalError( 'Not configured for cluster operations.' );
		}
		return $cluster;
	}

	/**
	 * Execute a callback function at the end of initialisation
	 */
	public function loadSpecialVars() {
		parent::loadSpecialVars();
		if ( self::$indent === null ) {
			// First script gets no indentation
			self::$indent = '';
		} else {
			// Others get one tab beyond the last
			self::$indent .= "\t";
		}
	}

	/**
	 * Call to signal that execution of this maintenance script is complete so
	 * the next one gets the right indentation.
	 */
	public function done() {
		self::$indent = substr( self::$indent, 1 );
	}

	/**
	 * @param string $message
	 * @param string|null $channel
	 */
	public function output( $message, $channel = null ) {
		parent::output( $message );
	}

	public function outputIndented( $message ) {
		$this->output( self::$indent . $message );
	}

	/**
	 * @param string $err
	 * @param int $die deprecated, do not use
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
		unset( $wgPoolCounterConf['CirrusSearch-Search'] );

		// Don't skew the dashboards by logging these requests to
		// the global request log.
		$wgCirrusSearchLogElasticRequests = false;
		// Disable statsd data collection.
		$stats = MediaWikiServices::getInstance()->getStatsdDataFactory();
		$stats->setEnabled( false );
	}

	/**
	 * Create metastore only if the alias does not already exist
	 * @return MetaStoreIndex
	 */
	protected function maybeCreateMetastore() {
		$metastore = new MetaStoreIndex(
			$this->getConnection(),
			$this,
			$this->getSearchConfig() );
		$status = $metastore->createIfNecessary();
		$this->unwrap( $status );
		return $metastore;
	}

	protected function requireCirrusReady() {
		// If the version does not exist it's certainly because nothing has been indexed.
		if ( !$this->getMetaStore()->cirrusReady() ) {
			throw new RuntimeException(
				"Cirrus meta store does not exist, you must index your data first"
			);
		}
	}

	/**
	 * Provides support for backward compatible CLI options
	 *
	 * Requires either one or neither of the two options to be provided.
	 *
	 * @param string $current The current option to request
	 * @param string $bc The old option to provide BC support for
	 * @param bool $required True if the option must be provided. When false and no option
	 *  is provided null is returned.
	 * @return mixed
	 */
	protected function getBackCompatOption( string $current, string $bc, bool $required = true ) {
		if ( $this->hasOption( $current ) && $this->hasOption( $bc ) ) {
			$this->error( "\nERROR: --$current cannot be provided with --$bc" );
			$this->maybeHelp( true );
		} elseif ( $this->hasOption( $current ) ) {
			return $this->getOption( $current );
		} elseif ( $this->hasOption( $bc ) ) {
			return $this->getOption( $bc );
		} elseif ( $required ) {
			$this->error( "\nERROR: Param $current is required" );
			$this->maybeHelp( true );
		} else {
			return null;
		}
	}

	/**
	 * Helper method for Status returning methods, such as via ConfigUtils
	 *
	 * @param Status $status
	 * @return mixed
	 */
	protected function unwrap( Status $status ) {
		if ( !$status->isGood() ) {
			$this->fatalError( (string)$status );
		}
		return $status->getValue();
	}

}
