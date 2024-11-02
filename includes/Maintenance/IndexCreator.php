<?php

namespace CirrusSearch\Maintenance;

use Elastica\Index;
use MediaWiki\Status\Status;

class IndexCreator {

	/**
	 * @var Index
	 */
	private $index;

	/**
	 * @var array
	 */
	private $analysisConfig;

	/**
	 * @var array|null
	 */
	private $similarityConfig;

	/**
	 * @var array
	 */
	private $mappings;

	/**
	 * @var ConfigUtils
	 */
	private $utils;

	/**
	 * @var int How long to wait for index to become green, in seconds
	 */
	private $greenTimeout;

	/**
	 * @param Index $index
	 * @param ConfigUtils $utils
	 * @param array $analysisConfig
	 * @param array $mappings
	 * @param array|null $similarityConfig
	 * @param int $greenTimeout How long to wait for index to become green, in seconds
	 */
	public function __construct(
		Index $index,
		ConfigUtils $utils,
		array $analysisConfig,
		array $mappings,
		?array $similarityConfig = null,
		$greenTimeout = 120
	) {
		$this->index = $index;
		$this->utils = $utils;
		$this->analysisConfig = $analysisConfig;
		$this->similarityConfig = $similarityConfig;
		$this->mappings = $mappings;
		$this->greenTimeout = $greenTimeout;
	}

	/**
	 * @param bool $rebuild
	 * @param int $maxShardsPerNode
	 * @param int $shardCount
	 * @param string $replicaCount
	 * @param int $refreshInterval
	 * @param array $mergeSettings
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
		array $extraSettings
	) {
		$args = [
			'settings' => $this->buildSettings(
				$maxShardsPerNode,
				$shardCount,
				$replicaCount,
				$refreshInterval,
				$mergeSettings,
				$extraSettings
			),
			'mappings' => $this->mappings,
		];

		try {
			$response = $this->index->create( $args, [ 'recreate' => $rebuild ] );

			if ( $response->hasError() === true ) {
				return Status::newFatal( $response->getError() );
			}
		} catch ( \Elastica\Exception\InvalidException | \Elastica\Exception\ResponseException $ex ) {
			return Status::newFatal( $ex->getMessage() );
		}

		// On wikis with particularly large mappings, such as wikibase, sometimes we
		// see a race where elastic says it created the index, but then a quick followup
		// request 404's. Wait for green to ensure it's really ready.
		if ( !$this->utils->waitForGreen( $this->index->getName(), $this->greenTimeout ) ) {
			return Status::newFatal( 'Created index did not reach green state.' );
		}

		return Status::newGood();
	}

	/**
	 * @param int $maxShardsPerNode
	 * @param int $shardCount
	 * @param string $replicaCount
	 * @param int $refreshInterval
	 * @param array $mergeSettings
	 * @param array $extraSettings
	 *
	 * @return array
	 */
	private function buildSettings(
		$maxShardsPerNode,
		$shardCount,
		$replicaCount,
		$refreshInterval,
		array $mergeSettings,
		array $extraSettings
	) {
		$indexSettings = [
			'number_of_shards' => $shardCount,
			'auto_expand_replicas' => $replicaCount,
			'refresh_interval' => $refreshInterval . 's',
			'analysis' => $this->analysisConfig,
			'routing' => [
				'allocation.total_shards_per_node' => $maxShardsPerNode,
			]
		];

		if ( $mergeSettings ) {
			$indexSettings['merge.policy'] = $mergeSettings;
		}

		$similarity = $this->similarityConfig;
		if ( $similarity ) {
			$indexSettings['similarity'] = $similarity;
		}

		// Use our weighted all field as the default rather than _all which is disabled.
		$indexSettings['query.default_field'] = 'all';

		// ideally we should merge $extraSettings to $indexSettings
		// but existing config might declare keys like "index.mapping.total_fields.limit"
		// which would not work under the 'index' key.
		return [ 'index' => $indexSettings ] + $extraSettings;
	}

}
