<?php

namespace CirrusSearch\Maintenance;

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
	 * @param array $extraSettings
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
		$searchAllFields,
		array $extraSettings
	) {
		$args = $this->buildArgs(
			$maxShardsPerNode,
			$shardCount,
			$replicaCount,
			$refreshInterval,
			$mergeSettings,
			$searchAllFields,
			$extraSettings
		);

		try {
			$response = $this->index->create( $args, $rebuild );

			/** @suppress PhanNonClassMethodCall library is mis-annotated */
			if ( $response->hasError() === true ) {
				/** @suppress PhanNonClassMethodCall library is mis-annotated */
				return Status::newFatal( $response->getError() );
			}
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
	 * @param array $extraSettings
	 *
	 * @return array
	 */
	private function buildArgs(
		$maxShardsPerNode,
		$shardCount,
		$replicaCount,
		$refreshInterval,
		array $mergeSettings,
		$searchAllFields,
		array $extraSettings
	) {
		$maxShardsPerNode = $maxShardsPerNode === 'unlimited' ? -1 : $maxShardsPerNode;
		$args = [
			'settings' => [
				'number_of_shards' => $shardCount,
				'auto_expand_replicas' => $replicaCount,
				'analysis' => $this->analysisConfigBuilder->buildConfig(),
				'refresh_interval' => $refreshInterval . 's',
				'merge.policy' => $mergeSettings,
				'routing.allocation.total_shards_per_node' => $maxShardsPerNode,
			] + $extraSettings
		];
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
