<?php

namespace CirrusSearch\Maintenance\Validators;

use CirrusSearch\Maintenance\AnalysisConfigBuilder;
use CirrusSearch\Maintenance\Maintenance;
use Elastica\Index;
use Status;

class IndexValidator extends Validator {
	/**
	 * @var Index
	 */
	private $index;

	/**
	 * @var bool
	 */
	private $startOver;

	/**
	 * @var int
	 */
	private $maxShardsPerNode;

	/**
	 * @var int
	 */
	private $shardCount;

	/**
	 * @var string
	 */
	private $replicaCount;

	/**
	 * @var AnalysisConfigBuilder
	 */
	private $analysisConfigBuilder;

	/**
	 * @var array
	 */
	private $mergeSettings;

	/**
	 * @param Index $index
	 * @param bool $startOver
	 * @param string $maxShardsPerNode
	 * @param int $shardCount
	 * @param string $replicaCount
	 * @param int $refreshInterval
	 * @param AnalysisConfigBuilder $analysisConfigBuilder
	 * @param array $mergeSettings
	 * @param Maintenance $out
	 */
	public function __construct( Index $index, $startOver, $maxShardsPerNode, $shardCount, $replicaCount, $refreshInterval, AnalysisConfigBuilder $analysisConfigBuilder, array $mergeSettings, Maintenance $out = null ) {
		parent::__construct( $out );

		$this->index = $index;
		$this->startOver = $startOver;
		$this->maxShardsPerNode = $maxShardsPerNode;
		$this->shardCount = $shardCount;
		$this->replicaCount = $replicaCount;
		$this->refreshInterval = $refreshInterval;
		$this->analysisConfigBuilder = $analysisConfigBuilder;
		$this->mergeSettings = $mergeSettings;
	}

	/**
	 * @return Status
	 */
	public function validate() {
		if ( $this->startOver ) {
			$this->outputIndented( "Blowing away index to start over..." );
			$this->createIndex( true );
			$this->output( "ok\n" );
			return Status::newGood();
		}
		if ( !$this->index->exists() ) {
			$this->outputIndented( "Creating index..." );
			$this->createIndex( false );
			$this->output( "ok\n" );
			return Status::newGood();
		}
		$this->outputIndented( "Index exists so validating...\n" );

		return Status::newGood();
	}

	/**
	 * @param bool $rebuild
	 */
	private function createIndex( $rebuild ) {
		$maxShardsPerNode = $this->maxShardsPerNode === 'unlimited' ? -1 : $this->maxShardsPerNode;
		$this->index->create( array(
			'settings' => array(
				'number_of_shards' => $this->shardCount,
				'auto_expand_replicas' => $this->replicaCount,
				'analysis' => $this->analysisConfigBuilder->buildConfig(),
				// Use our weighted all field as the default rather than _all which is disabled.
				'index.query.default_field' => 'all',
				'refresh_interval' => $this->refreshInterval . 's',
				'merge.policy' => $this->mergeSettings,
				'routing.allocation.total_shards_per_node' => $maxShardsPerNode,
			)
		), $rebuild );
	}
}
