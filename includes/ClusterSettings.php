<?php

namespace CirrusSearch;

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

	/**
	 * @param string $indexType
	 * @return integer Number of shards the index should have
	 */
	public function getShardCount( $indexType ) {
		$settings = $this->config->get( 'CirrusSearchShardCount' );
		if ( isset( $settings[$this->cluster][$indexType] ) ) {
			return $settings[$this->cluster][$indexType];
		} elseif ( isset( $settings[$indexType] ) ) {
			return $settings[$indexType];
		}
		throw new \Exception( "Could not find a shard count for "
			. "{$this->indexType}. Did you add an index to "
			. "\$wgCirrusSearchNamespaceMappings but forget to "
			. "add it to \$wgCirrusSearchShardCount?" );
	}

	/**
	 * @param string $indexType
	 * @return string Number of replicas Elasticsearch can expand or contract to.
	 */
	public function getReplicaCount( $indexType ) {
		$settings = $this->config->get( 'CirrusSearchReplicas' );
		if ( !is_array( $settings ) ) {
			return $settings;
		} elseif ( isset( $settings[$this->cluster][$indexType] ) ) {
			return $settings[$this->cluster][$indexType];
		} elseif ( isset( $settings[$indexType] ) ) {
			return $settings[$indexType];
		}
		throw new \Exception( "If \$wgCirrusSearchReplicas is " .
			"an array it must contain all index types." );
	}
}
