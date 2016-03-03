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
	 * @var int
	 */
	private $refreshInterval;

	/**
	 * @var bool
	 */
	private $searchAllFields;

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
	 * @param bool $searchAllFields
	 * @param AnalysisConfigBuilder $analysisConfigBuilder
	 * @param array $mergeSettings
	 * @param Maintenance $out
	 */
	public function __construct(
		Index $index,
		$startOver,
		$maxShardsPerNode,
		$shardCount,
		$replicaCount,
		$refreshInterval,
		$searchAllFields,
		AnalysisConfigBuilder $analysisConfigBuilder,
		array $mergeSettings,
		Maintenance $out = null
	) {
		parent::__construct( $out );

		$this->index = $index;
		$this->startOver = $startOver;
		$this->maxShardsPerNode = $maxShardsPerNode;
		$this->shardCount = $shardCount;
		$this->replicaCount = $replicaCount;
		$this->refreshInterval = $refreshInterval;
		$this->searchAllFields = $searchAllFields;
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
		$args = $this->buildArgs();

		$this->index->create( $args, $rebuild );
	}

	/**
	 * @return array
	 */
	private function buildArgs() {
		$maxShardsPerNode = $this->maxShardsPerNode === 'unlimited' ? -1 : $this->maxShardsPerNode;
		$args = array(
			'settings' => array(
				'number_of_shards' => $this->shardCount,
				'auto_expand_replicas' => $this->replicaCount,
				'analysis' => $this->analysisConfigBuilder->buildConfig(),
				'refresh_interval' => $this->refreshInterval . 's',
				'merge.policy' => $this->mergeSettings,
				'routing.allocation.total_shards_per_node' => $maxShardsPerNode,
			)
		);

		if ( $this->searchAllFields ) {
			// Use our weighted all field as the default rather than _all which is disabled.
			$args['settings']['index.query.default_field'] = 'all';
		}

		return $args;
	}

}
