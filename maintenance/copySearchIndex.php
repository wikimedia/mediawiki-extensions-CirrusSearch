<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\Connection;
use CirrusSearch\Util;
use ConfigFactory;
use Elastica;
use CirrusSearch\ClusterSettings;

/**
 * Copy search index from one cluster to another.
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
if( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once( "$IP/maintenance/Maintenance.php" );
require_once( __DIR__ . '/../includes/Maintenance/Maintenance.php' );

/**
 * Update the elasticsearch configuration for this index.
 */
class CopySearchIndex extends Maintenance {
	private $indexType;

	private $indexBaseName;
	/**
	 * @var int
	 */
	protected $refreshInterval;

	public function __construct() {
		parent::__construct();
		$this->addDescription( "Copy index from one cluster to another.\nThe index name and index type should be the same on both clusters." );
		$this->addOption( 'indexType', 'Source index.  Either content or general.', true, true );
		$this->addOption( 'targetCluster', 'Target Cluster.', true, true );
		$this->addOption( 'reindexChunkSize', 'Documents per shard to reindex in a batch.   ' .
		    'Note when changing the number of shards that the old shard size is used, not the new ' .
		    'one.  If you see many errors submitting documents in bulk but the automatic retry as ' .
		    'singles works then lower this number.  Defaults to 100.', false, true );
		$this->addOption( 'reindexRetryAttempts', 'Number of times to back off and retry ' .
			'per failure.  Note that failures are not common but if Elasticsearch is in the process ' .
			'of moving a shard this can time out.  This will retry the attempt after some backoff ' .
			'rather than failing the whole reindex process.  Defaults to 5.', false, true );
	}

	public function execute() {
		global $wgCirrusSearchMaintenanceTimeout;

		$this->indexType = $this->getOption( 'indexType' );
		$this->indexBaseName = $this->getOption( 'baseName', wfWikiId() );

		$reindexChunkSize = $this->getOption( 'reindexChunkSize', 100 );
		$reindexRetryAttempts = $this->getOption( 'reindexRetryAttempts', 5 );
		$targetCluster = $this->getOption( 'targetCluster' );

		$sourceConnection = $this->getConnection();
		$targetConnection = $this->getConnection( $targetCluster );

		if ( $sourceConnection->getClusterName() == $targetConnection->getClusterName() ) {
			$this->error("Target cluster should be different from current cluster.", 1);
		}
		$config = ConfigFactory::getDefaultInstance()->makeConfig( 'CirrusSearch' );
		$clusterSettings = new ClusterSettings( $config, $targetConnection->getClusterName() );

		$targetIndexName = $targetConnection->getIndexName( $this->indexBaseName, $this->indexType );
		$utils = new ConfigUtils( $targetConnection->getClient(), $this );
		$indexIdentifier = $utils->pickIndexIdentifierFromOption( $this->getOption( 'indexIdentifier', 'current' ),
				 $targetIndexName );

		$reindexer = new Reindexer(
				$sourceConnection,
				$targetConnection,
				// Target Index
				array( $targetConnection->getIndex( $this->indexBaseName, $this->indexType,
						$indexIdentifier )->getType( Connection::PAGE_TYPE_NAME )
				),
				// Source Index
				array( $this->getConnection()->getPageType( $this->indexBaseName, $this->indexType ) ),
				$clusterSettings->getShardCount( $this->indexType ),
				$clusterSettings->getReplicaCount( $this->indexType ),
				$wgCirrusSearchMaintenanceTimeout,
				$this->getMergeSettings(),
				$this->getMappingConfig(),
				$this
		);
		$reindexer->reindex( 1, 1, $reindexRetryAttempts, $reindexChunkSize);
		$reindexer->optimize();
		$reindexer->waitForShards();
	}

	/**
	 * Get the merge settings for this index.
	 */
	private function getMergeSettings() {
		global $wgCirrusSearchMergeSettings;

		if ( isset( $wgCirrusSearchMergeSettings[ $this->indexType ] ) ) {
			return $wgCirrusSearchMergeSettings[ $this->indexType ];
		}
		// If there aren't configured merge settings for this index type default to the content type.
		return $wgCirrusSearchMergeSettings[ 'content' ];
	}

	/**
	 * @return array
	 */
	protected function getMappingConfig() {
		global $wgCirrusSearchPrefixSearchStartsWithAnyWord, $wgCirrusSearchPhraseSuggestUseText,
		$wgCirrusSearchOptimizeIndexForExperimentalHighlighter;

		$builder = new MappingConfigBuilder( $wgCirrusSearchOptimizeIndexForExperimentalHighlighter );
		$configFlags = 0;
		if ( $wgCirrusSearchPrefixSearchStartsWithAnyWord ) {
			$configFlags |= MappingConfigBuilder::PREFIX_START_WITH_ANY;
		}
		if ( $wgCirrusSearchPhraseSuggestUseText ) {
			$configFlags |= MappingConfigBuilder::PHRASE_SUGGEST_USE_TEXT;
		}
		return $builder->buildConfig( $configFlags );
	}

}

$maintClass = 'CirrusSearch\Maintenance\CopySearchIndex';
require_once RUN_MAINTENANCE_IF_MAIN;
