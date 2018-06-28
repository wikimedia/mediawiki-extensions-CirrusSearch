<?php

namespace CirrusSearch;

/**
 * Represents an external index referenced by the OtherIndex functionality.
 * Typically sourced from $wgCirrusSearchExtraIndex.
 */
class ExternalIndex {
	/**
	 * @var SearchConfig
	 */
	private $config;

	/**
	 * @var string[] Map from cluster name to the related external cluster. Any
	 *  cluster not mapped will take assumption index is found on same cluster.
	 */
	private $clusters;

	/**
	 * @var string Name of index on external clusters
	 */
	private $indexName;

	/**
	 * @param Searchconfig $config
	 * @param string $indexName Name of index on external clusters.
	 */
	public function __construct( SearchConfig $config, $indexName ) {
		$this->config = $config;
		$this->indexName = $indexName;
		$this->clusters = $config->get( 'CirrusSearchExtraIndexClusters' )[$indexName] ?? [];
	}

	/**
	 * @return string The name of the external index.
	 */
	public function getIndexName() {
		return $this->indexName;
	}

	/**
	 * @param string $sourceCluster The cluster primary wiki writes
	 *  are being sent to.
	 * @return string The cluster external index writes must be sent to.
	 */
	public function getWriteCluster( $sourceCluster ) {
		return $this->clusters[$sourceCluster] ?? $sourceCluster;
	}

	/**
	 * @param string $sourceCluster Name of the cluster being queried.
	 * @return string The name of the index to search. Includes
	 *   cross-cluster identifier if necessary.
	 */
	public function getSearchIndex( $sourceCluster ) {
		$targetCluster = $this->clusters[$sourceCluster] ?? $sourceCluster;
		return $sourceCluster === $targetCluster
			? $this->indexName
			: "{$targetCluster}:{$this->indexName}";
	}

	/**
	 * @return array Two item array first containing a wiki name and second a map
	 *  from template name to weight for that template.
	 */
	public function getBoosts() {
		$boosts = $this->config->getElement( 'CirrusSearchExtraIndexBoostTemplates', $this->indexName );
		if ( isset( $boosts['wiki'], $boosts['boosts'] ) ) {
			return [ $boosts['wiki'], $boosts['boosts'] ];
		} else {
			return [ '', [] ];
		}
	}
}
