<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\Maintenance\AnalysisConfigBuilder;
use Elastica\Index;
use Status;

class IndexCreator {

	/**
	 * @var Index
	 */
	private $index;

	/**
	 * @var AnalysisConfigBuilder
	 */
	private $analysisConfigBuilder;

	/**
	 * @param Index $index
	 * @param AnalysisConfigBuilder $analysisConfigBuilder
	 */
	public function __construct( Index $index, AnalysisConfigBuilder $analysisConfigBuilder ) {
		$this->index = $index;
		$this->analysisConfigBuilder = $analysisConfigBuilder;
	}

	/**
	 * @param bool $rebuild
	 * @param int|string $maxShardsPerNode 'unlimited'
	 * @param int $shardCount
	 * @param string $replicaCount
	 * @param int $refreshInterval
	 * @param array $mergeSettings
	 * @param bool $searchAllFields
	 *
	 * @return Status
	 */
	public function createIndex(
		$rebuild,
		$maxShardsPerNode,
		$shardCount,
		$replicaCount,
		$refreshInterval,
		array $mergeSettings,
		$searchAllFields
	) {
		$args = $this->buildArgs(
			$maxShardsPerNode,
			$shardCount,
			$replicaCount,
			$refreshInterval,
			$mergeSettings,
			$searchAllFields
		);

		try {
			$this->index->create( $args, $rebuild );
		} catch ( \Elastica\Exception\InvalidException $ex ) {
			return Status::newFatal( $ex->getMessage() );
		} catch ( \Elastica\Exception\ResponseException $ex ) {
			return Status::newFatal( $ex->getMessage() );
		}

		return Status::newGood();
	}

	/**
	 * @param int|string $maxShardsPerNode 'unlimited'
	 * @param int $shardCount
	 * @param string $replicaCount
	 * @param int $refreshInterval
	 * @param array $mergeSettings
	 * @param bool $searchAllFields
	 *
	 * @return array
	 */
	private function buildArgs(
		$maxShardsPerNode,
		$shardCount,
		$replicaCount,
		$refreshInterval,
		array $mergeSettings,
		$searchAllFields
	) {
		$maxShardsPerNode = $maxShardsPerNode === 'unlimited' ? -1 : $maxShardsPerNode;
		$args = array(
			'settings' => array(
				'number_of_shards' => $shardCount,
				'auto_expand_replicas' => $replicaCount,
				'analysis' => $this->analysisConfigBuilder->buildConfig(),
				'refresh_interval' => $refreshInterval . 's',
				'merge.policy' => $mergeSettings,
				'routing.allocation.total_shards_per_node' => $maxShardsPerNode,
			)
		);
		$similarity = $this->analysisConfigBuilder->buildSimilarityConfig();
		if ( $similarity ) {
			$args['settings']['similarity'] = $similarity;
		}

		if ( $searchAllFields ) {
			// Use our weighted all field as the default rather than _all which is disabled.
			$args['settings']['index.query.default_field'] = 'all';
		}

		return $args;
	}

}
