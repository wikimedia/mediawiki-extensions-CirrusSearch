<?php

namespace CirrusSearch;

use RuntimeException;

/**
 * Handles resolving configuration variables into specific settings
 * for a specific cluster.
 */
class ClusterSettings {

	/**
	 * @var SearchConfig
	 */
	protected $config;

	/**
	 * @var string
	 */
	protected $cluster;

	/**
	 * @param SearchConfig $config
	 * @param string $cluster
	 */
	public function __construct( SearchConfig $config, $cluster ) {
		$this->config = $config;
		$this->cluster = $cluster;
	}

	public function getName(): string {
		return $this->cluster;
	}

	/**
	 * @return bool True when the cluster is allowed to contain private indices
	 */
	public function isPrivateCluster() {
		$privateClusters = $this->config->get( 'CirrusSearchPrivateClusters' );
		if ( $privateClusters === null ) {
			return true;
		} else {
			return in_array( $this->cluster, $privateClusters );
		}
	}

	/**
	 * @param string $indexSuffix
	 * @return int Number of shards the index should have
	 */
	public function getShardCount( $indexSuffix ) {
		$settings = $this->config->get( 'CirrusSearchShardCount' );
		if ( isset( $settings[$this->cluster][$indexSuffix] ) ) {
			return $settings[$this->cluster][$indexSuffix];
		} elseif ( isset( $settings[$indexSuffix] ) ) {
			return $settings[$indexSuffix];
		}
		throw new RuntimeException( "Could not find a shard count for "
			. "{$indexSuffix}. Did you add an index to "
			. "\$wgCirrusSearchNamespaceMappings but forget to "
			. "add it to \$wgCirrusSearchShardCount?" );
	}

	/**
	 * @param string $indexSuffix
	 * @return string Number of replicas Elasticsearch can expand or contract to
	 *  in the format of '0-2' for the minimum and maximum number of replicas. May
	 *  also be the string 'false' when replicas are disabled.
	 */
	public function getReplicaCount( $indexSuffix ) {
		$settings = $this->config->get( 'CirrusSearchReplicas' );
		if ( !is_array( $settings ) ) {
			return $settings;
		} elseif ( isset( $settings[$this->cluster][$indexSuffix] ) ) {
			return $settings[$this->cluster][$indexSuffix];
		} elseif ( isset( $settings[$indexSuffix] ) ) {
			return $settings[$indexSuffix];
		}
		throw new RuntimeException( "If \$wgCirrusSearchReplicas is " .
			"an array it must contain all index types." );
	}

	/**
	 * @param string $indexSuffix
	 * @return int Number of shards per node, or 'unlimited'.
	 */
	public function getMaxShardsPerNode( $indexSuffix ) {
		$settings = $this->config->get( 'CirrusSearchMaxShardsPerNode' );
		$max = $settings[$this->cluster][$indexSuffix] ?? $settings[$indexSuffix] ?? -1;
		// Allow convenience setting of 'unlimited' which translates to elasticsearch -1 (unbounded).
		return $max === 'unlimited' ? -1 : $max;
	}

	/**
	 * @return bool True when write isolation is configured for this cluster.
	 */
	public function isIsolated(): bool {
		$isolate = $this->config->get( 'CirrusSearchWriteIsolateClusters' );
		return $isolate === null || in_array( $this->cluster, $isolate );
	}

	/**
	 * @return int Number of partitions the ElasticaWrite job can be split into
	 */
	public function getElasticaWritePartitionCount(): int {
		$settings = $this->config->get( 'CirrusSearchElasticaWritePartitionCounts' );
		return $settings[$this->cluster] ?? 1;
	}

	/**
	 * @return int Connect timeout to use when initializing connection.
	 * Fallback to 0 (300 sec) if not specified in cirrus config.
	 */
	public function getConnectTimeout() {
		$timeout = $this->config->get( 'CirrusSearchClientSideConnectTimeout' );
		if ( is_int( $timeout ) ) {
			return $timeout;
		}
		// 0 means no timeout (defaults to 300 sec)
		return $timeout[$this->cluster] ?? 0;
	}
}
