<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\SearchConfig;
use Elastica\JSON;

/**
 * Update and check the CirrusSearch metastore index.
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

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
require_once __DIR__ . '/../includes/Maintenance/Maintenance.php';

class Metastore extends Maintenance {
	/**
	 * @var MetaStoreIndex
	 */
	private $metaStore;

	public function __construct() {
		parent::__construct();
		$this->mDescription = "Update and check the CirrusSearch metastore index. Always operates on a single cluster.";
		$this->addOption( 'upgrade', 'Create or upgrade the metastore index.' );
		$this->addOption( 'show-all-index-versions', 'Show all versions for all indices managed by this cluster.' );
		$this->addOption( 'show-index-version', 'Show index versions for this wiki.' );
		$this->addOption( 'update-index-version', 'Update the version '.
			'index for this wiki. Dangerous: index versions should be managed '.
			'by updateSearchIndexConfig.php.' );
		$this->addOption( 'index-version-basename', 'What basename to use when running --show-index-version '.
			'or --update-index-version, ' .
			'defaults to wiki id', false, true );
		$this->addOption( 'dump', 'Dump the metastore index to stdout (elasticsearch bulk index format).' );
	}

	public function execute() {
		$this->metaStore = new MetaStoreIndex( $this->getConnection(), $this );

		if ( $this->hasOption( 'dump' ) ) {
			$this->dump();
			return;
		}

		// Check if the metastore is usable
		if ( !MetaStoreIndex::cirrusReady( $this->getConnection() ) ) {
			// This is certainly a fresh install we need to create
			// the metastore otherwize updateSearchIndexConfig will fail
			$this->metaStore->createOrUpgradeIfNecessary();
		}

		if ( $this->hasOption( 'version' ) ) {
			$storeVersion = $this->metaStore->metastoreVersion();
			$runtimeVersion = $this->metaStore->runtimeVersion();
			if ( $storeVersion != $runtimeVersion ) {
				$this->output( "mw_cirrus_metastore is running an old version (" .
					implode( '.', $storeVersion ) . ") please upgrade to " .
					implode( '.', $runtimeVersion ) .
					" by running metastore.php --upgrade\n" );
			} else {
				$this->output( "mw_cirrus_metastore is up to date and running with version " .
					implode( '.', $storeVersion ) . "\n" );
			}
		} elseif ( $this->hasOption( 'upgrade' ) ) {
			$this->metaStore->createOrUpgradeIfNecessary();
			$this->output( "mw_cirrus_metastore is up and running with version " .
				implode( '.', $this->metaStore->metastoreVersion() ) . "\n" );
		} elseif ( $this->hasOption( 'show-all-index-versions' ) ) {
			$this->showIndexVersions();
		} elseif ( $this->hasOption( 'update-index-version' ) ) {
			$baseName = $this->getOption( 'index-version-basename', $this->getSearchConfig()->get( SearchConfig::INDEX_BASE_NAME ) );
			$this->updateIndexVersion( $baseName );
		} elseif ( $this->hasOption( 'show-index-version' ) ) {
			$baseName = $this->getOption( 'index-version-basename', $this->getSearchConfig()->get( SearchConfig::INDEX_BASE_NAME ) );
			$filter = new \Elastica\Query\BoolQuery();
			$ids = new \Elastica\Query\Ids();
			foreach ( $this->getConnection()->getAllIndexTypes() as $type ) {
				$ids->addId( $this->getConnection()->getIndexName( $baseName, $type ) );
			}
			$filter->addFilter( $ids );
			$this->showIndexVersions( $filter );
		} else {
			$this->maybeHelp( true );
		}
	}

	/**
	 * @param array|\Elastica\Query\AbstractQuery|null $filter
	 */
	private function showIndexVersions( $filter = null ) {
		$query = new \Elastica\Query();
		if ( $filter ) {
			$query->setQuery( $filter );
		}
		// WHAT ARE YOU DOING TRACKING MORE THAN 5000 INDEXES?!?
		$query->setSize( 5000 );
		$res = $this->metaStore->versionType()->search( $query );
		foreach ( $res as $r ) {
			$data = $r->getData();
			$this->outputIndented( "index name: " . $r->getId() . "\n" );
			$this->outputIndented( "  analysis version: {$data['analysis_maj']}.{$data['analysis_min']}\n" );
			$this->outputIndented( "  mapping version: {$data['mapping_maj']}.{$data['mapping_min']}\n" );
			if ( isset( $data['mediawiki_version'] ) ) {
				$this->outputIndented( "  code version: {$data['mediawiki_version']} ({$data['mediawiki_commit']}, Cirrus: {$data['cirrus_commit']})\n" );
			}
			$this->outputIndented( "  shards: {$data['shard_count']}\n" );
		}
	}

	/**
	 * @param string $baseName
	 */
	private function updateIndexVersion( $baseName ) {
		$this->outputIndented( "Updating tracking indexes..." );
		$this->metaStore->updateAllVersions( $baseName );
		$this->output( "done\n" );
	}

	private function dump() {
		$index = $this->getConnection()->getIndex( MetaStoreIndex::INDEX_NAME );
		if ( !$index->exists() ) {
			$this->fatalError( "Cannot dump metastore: index does not exists. Please run --upgrade first" );
		}

		$query = new \Elastica\Query();
		$query->setQuery( new \Elastica\Query\MatchAll() );
		$query->setSize( 100 );
		$query->setSource( true );
		$query->setSort( [ '_doc' ] );
		$search = $index->createSearch( $query );
		$scroll = new \Elastica\Scroll( $search, '15m' );
		foreach ( $scroll as $results ) {
			foreach ( $results as $result ) {
				$indexOp = [
					'index' => [
						'_type' => $result->getType(),
						'_id' => $result->getId(),
					]
				];
				fwrite( STDOUT, JSON::stringify( $indexOp ) . "\n" );
				fwrite( STDOUT, JSON::stringify( $result->getSource() ) . "\n" );
			}
		}
	}
}

$maintClass = Metastore::class;
require_once RUN_MAINTENANCE_IF_MAIN;
