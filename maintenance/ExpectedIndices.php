<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\Connection;
use CirrusSearch\SearchConfig;
use WikiMap;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
require_once __DIR__ . '/../includes/Maintenance/Maintenance.php';

/**
 * Reports index aliases that CirrusSearch owns for this wiki.
 *
 * This information can be used as part of a more complete solution to
 * account for the indices that should exist on an elasticsearch cluster.
 * The output here is strictly related to the configuration of CirrusSearch
 * and does not reference state of any live cluster.
 *
 * CirrusSearch almost always refers to indices by alias, the only time
 * when CirrusSearch owns an index without an alias is during index
 * creation and reindexing. A reasonable proxy to detect this would be
 * updates in the last few minutes. If CirrusSearch owns an index but
 * does not have an alias yet it will be under constant indexing load.
 */
class ExpectedIndices extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Report index alias that CirrusSearch owns.' );
		$this->addOption( 'oneline', 'Dont pretty print the output', false, false );
	}

	public function execute() {
		$clusters = $this->requestedClusters(
			$this->getOption( 'cluster', null ) );
		echo \FormatJson::encode( [
			'dbname' => WikiMap::getCurrentWikiId(),
			'clusters' => $this->clusterInfo( $clusters ),
		], !$this->getOption( 'oneline' ) ), "\n";
	}

	private function clusterInfo( array $clusters ): array {
		$config = $this->getSearchConfig();
		$assignment = $config->getClusterAssignment();
		$output = [];
		foreach ( $clusters as $clusterName ) {
			$output[$clusterName] = [
				'aliases' => $this->allIndexNames(
					Connection::getPool( $config, $clusterName ) ),
				'group' => $assignment->getCrossClusterName(),
				// Group should satisfy most automated use cases, server list
				// is more for debugging or verifying.
				'connection' => $assignment->getServerList( $clusterName )
			];
		}
		return $output;
	}

	private function requestedClusters( ?string $requested ): array {
		$assignment = $this->getSearchConfig()->getClusterAssignment();
		if ( $requested !== null ) {
			// Single cluster
			return $assignment->canWriteToCluster( $requested )
				? [ $requested ]
				: [];
		}
		return $assignment->getWritableClusters();
	}

	private function allIndexNames( Connection $conn ): array {
		$config = $this->getSearchConfig();
		$baseName = $config->get( SearchConfig::INDEX_BASE_NAME );
		$types = $conn->getAllIndexTypes( null );
		if ( $config->isCompletionSuggesterEnabled() ) {
			$types[] = Connection::TITLE_SUGGEST_TYPE;
		}
		$output = [];
		foreach ( $types as $indexType ) {
			$output[] = $conn->getIndexName( $baseName, $indexType );
		}
		return $output;
	}
}

$maintClass = ExpectedIndices::class;
require_once RUN_MAINTENANCE_IF_MAIN;
