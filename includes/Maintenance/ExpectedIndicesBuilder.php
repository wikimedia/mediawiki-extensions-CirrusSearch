<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\AlternativeIndices;
use CirrusSearch\ClusterSettings;
use CirrusSearch\Connection;
use CirrusSearch\SearchConfig;

class ExpectedIndicesBuilder {
	private SearchConfig $searchConfig;
	private AlternativeIndices $alternativeIndices;

	public function __construct( SearchConfig $searchConfig ) {
		$this->searchConfig = $searchConfig;
		$this->alternativeIndices = AlternativeIndices::build( $searchConfig );
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
			$connection = Connection::getPool( $this->searchConfig, $clusterName );
			$info = [
				'aliases' => $this->allIndexNames( $connection ),
				'shard_count' => $this->shardCounts( $connection ),
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
			return $assignment->canManageCluster( $requested )
				? [ $requested ]
				: [];
		}
		return $assignment->getManagedClusters();
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
		return array_merge( $output, array_keys( $this->getAlternativeIndexNames( $conn ) ) );
	}

	private function shardCounts( Connection $conn ): array {
		$baseName = $this->searchConfig->get( SearchConfig::INDEX_BASE_NAME );
		$suffixes = $conn->getAllIndexSuffixes( null );
		if ( $this->searchConfig->isCompletionSuggesterEnabled() ) {
			$suffixes[] = Connection::TITLE_SUGGEST_INDEX_SUFFIX;
		}

		$output = [];
		foreach ( $suffixes as $indexSuffix ) {
			$index = $conn->getIndexName( $baseName, $indexSuffix );
			$output[$index] = $conn->getSettings()->getShardCount( $indexSuffix );
		}
		foreach ( $this->getAlternativeIndexNames( $conn ) as $name => $typeAndConn ) {
			$output[$name] = $typeAndConn['settings']->getShardCount( $typeAndConn['type'] );
		}
		return $output;
	}

	private function getAlternativeIndexNames( Connection $conn ): array {
		if ( !$this->searchConfig->isCompletionSuggesterEnabled() ) {
			return [];
		}
		$altIndices = $this->alternativeIndices->getAlternativeIndices( AlternativeIndices::COMPLETION );
		$indices = [];
		foreach ( $altIndices as $index ) {
			$aliasName = $index->getIndex( $conn )->getName();
			$indices[$aliasName] = [
				"type" => Connection::TITLE_SUGGEST_INDEX_SUFFIX,
				"settings" => new ClusterSettings( $index->getConfig(), $conn->getClusterName() )
			];
		}
		return $indices;
	}

}
