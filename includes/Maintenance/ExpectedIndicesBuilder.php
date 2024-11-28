<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\Connection;
use CirrusSearch\SearchConfig;

class ExpectedIndicesBuilder {
	private SearchConfig $searchConfig;

	public function __construct( SearchConfig $searchConfig ) {
		$this->searchConfig = $searchConfig;
	}

	public function build( bool $withConnectionInfo, ?string $cluster ): array {
		$clusters = $this->requestedClusters( $cluster );
		return [
			'dbname' => $this->searchConfig->getWikiId(),
			'clusters' => $this->clusterInfo( $withConnectionInfo, $clusters ),
		];
	}

	private function clusterInfo( bool $withConnectionInfo, array $clusters ): array {
		$assignment = $this->searchConfig->getClusterAssignment();
		$output = [];
		foreach ( $clusters as $clusterName ) {
			$info = [
				'aliases' => $this->allIndexNames(
					Connection::getPool( $this->searchConfig, $clusterName ) ),
				'group' => $assignment->getCrossClusterName(),
			];
			if ( $withConnectionInfo ) {
				// Group should satisfy most automated use cases, server list
				// is more for debugging or verifying.
				$info['connection'] = $assignment->getServerList( $clusterName );
			}
			$output[$clusterName] = $info;
		}
		return $output;
	}

	private function requestedClusters( ?string $requested ): array {
		$assignment = $this->searchConfig->getClusterAssignment();
		if ( $requested !== null ) {
			return $assignment->hasCluster( $requested )
				? [ $requested ]
				: [];
		}
		return $assignment->getAllKnownClusters();
	}

	private function allIndexNames( Connection $conn ): array {
		$baseName = $this->searchConfig->get( SearchConfig::INDEX_BASE_NAME );
		$suffixes = $conn->getAllIndexSuffixes( null );
		if ( $this->searchConfig->isCompletionSuggesterEnabled() ) {
			$suffixes[] = Connection::TITLE_SUGGEST_INDEX_SUFFIX;
		}
		$output = [];
		foreach ( $suffixes as $indexSuffix ) {
			$output[] = $conn->getIndexName( $baseName, $indexSuffix );
		}
		return $output;
	}

}
