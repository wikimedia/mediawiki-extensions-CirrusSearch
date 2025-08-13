<?php

namespace CirrusSearch\Assignment;

use CirrusSearch\SearchConfig;
use Wikimedia\Assert\Assert;

class MultiClusterAssignment implements ClusterAssignment {
	/** @var SearchConfig */
	private $config;
	/** @var array[][]|null 2d array mapping (replica, group) to connection configuration */
	private $clusters;
	/** @var string */
	private $group;

	public function __construct( SearchConfig $config ) {
		$this->config = $config;
		$groupConfig = $config->get( 'CirrusSearchReplicaGroup' );
		if ( $groupConfig === null ) {
			throw new \RuntimeException( 'CirrusSearchReplicaGroup is null' );
		}
		if ( is_string( $groupConfig ) ) {
			$groupConfig = [
				'type' => 'constant',
				'group' => $groupConfig,
			];
		}
		$this->group = $this->evalGroupStrategy( $groupConfig );
	}

	/**
	 * @param array $groupConfig
	 * @return string
	 */
	private function evalGroupStrategy( array $groupConfig ) {
		// Determine which group this wiki belongs to
		switch ( $groupConfig['type'] ) {
			case 'constant':
				return $groupConfig['group'];
			case 'roundrobin':
				$wikiId = $this->config->getWikiId();
				$mod = count( $groupConfig['groups'] );
				Assert::precondition( $mod > 0, "At least one replica group must be defined for roundrobin" );
				$idx = crc32( $wikiId ) % $mod;
				return $groupConfig['groups'][$idx];
			default:
				throw new \RuntimeException( "Unknown replica group type: {$groupConfig['type']}" );
		}
	}

	private function initClusters(): array {
		$clusters = [];
		// We could require the input come in this shape, instead of reshaping
		// it when we start, but it seemed awkward to work with.
		foreach ( $this->config->get( 'CirrusSearchClusters' ) as $name => $config ) {
			$replica = $config['replica'] ?? $name;
			// Tempting to skip everything that doesn't match $this->group, but we have
			// to also track single group replicas with arbitrary group names.
			$group = $config['group'] ?? 'default';
			unset( $config['replica'], $config['group'] );
			if ( isset( $clusters[$replica][$group] ) ) {
				throw new \RuntimeException( "Multiple clusters for replica: $replica group: $group" );
			}
			$clusters[$replica][$group] = $config;
		}
		return $clusters;
	}

	/**
	 * @param string $cluster Name of requested cluster
	 * @return string Uniquely identifies the connection properties.
	 */
	public function uniqueId( $cluster ) {
		return "{$this->group}:$cluster";
	}

	/**
	 * @return string[] List of the cluster groups to manage indexes on.
	 */
	public function getManagedClusters(): array {
		$clusters = $this->config->get( 'CirrusSearchManagedClusters' );
		return $clusters ?? $this->getAllKnownClusters();
	}

	/**
	 * @param string $updateGroup UpdateGroup::* constant
	 * @return string[] List of CirrusSearch cluster names to write to.
	 */
	public function getWritableClusters( string $updateGroup ): array {
		$clusters = $this->config->get( 'CirrusSearchWriteClusters' );
		if ( $clusters === null ) {
			// No explicitly configured set of write clusters. Write to all known replicas.
			return $this->getAllKnownClusters();
		}
		if ( count( $clusters ) === 0 || isset( $clusters[0] ) ) {
			// Simple list of writable clusters
			return $clusters;
		}
		// Writable clusters defined per update group
		return $clusters[$updateGroup] ?? $clusters['default'];
	}

	private function getAllKnownClusters(): array {
		if ( $this->clusters === null ) {
			$this->clusters = $this->initClusters();
		}
		return array_keys( $this->clusters );
	}

	/**
	 * @param string $cluster
	 * @return bool True when the named cluster is in the set of managable clusters.
	 */
	public function canManageCluster( $cluster ): bool {
		return in_array( $cluster, $this->getManagedClusters() );
	}

	/**
	 * Check if a cluster is configured to accept writes
	 *
	 * @param string $cluster
	 * @param string $updateGroup UpdateGroup::* constant
	 * @return bool
	 */
	public function canWriteToCluster( $cluster, $updateGroup ) {
		return in_array( $cluster, $this->getWritableClusters( $updateGroup ) );
	}

	/**
	 * Check if a cluster is defined
	 *
	 * @param string $cluster
	 * @return bool
	 */
	public function hasCluster( string $cluster ): bool {
		if ( $this->clusters === null ) {
			$this->clusters = $this->initClusters();
		}
		return isset( $this->clusters[$cluster] );
	}

	/**
	 * @return string Name of the default search cluster.
	 */
	public function getSearchCluster() {
		return $this->config->get( 'CirrusSearchDefaultCluster' );
	}

	/**
	 * @return string Name to prefix indices with when
	 *  using cross-cluster-search.
	 */
	public function getCrossClusterName() {
		return $this->group;
	}

	/**
	 * @param string|null $replica
	 * @return string[]|array[]
	 */
	public function getServerList( $replica = null ): array {
		if ( $this->clusters === null ) {
			$this->clusters = $this->initClusters();
		}
		$replica ??= $this->config->get( 'CirrusSearchDefaultCluster' );
		if ( !isset( $this->clusters[$replica] ) ) {
			$available = implode( ',', array_keys( $this->clusters ) );
			throw new \RuntimeException( "Missing replica <$replica>, have <$available>" );
		} elseif ( isset( $this->clusters[$replica][$this->group] ) ) {
			return $this->clusters[$replica][$this->group];
		} elseif ( count( $this->clusters[$replica] ) === 1 ) {
			// If a replica only has a single elasticsearch cluster then by
			// definition everything goes there.
			return reset( $this->clusters[$replica] );
		} else {
			throw new \RuntimeException( "Missing replica: $replica group: {$this->group}" );
		}
	}
}
