<?php

namespace CirrusSearch\Maintenance\Validators;

use CirrusSearch\Maintenance\Maintenance;
use Elastica\Index;
use Status;

class MaxShardsPerNodeValidator extends Validator {
	/**
	 * @var Index
	 */
	private $index;

	/**
	 * @var string
	 */
	private $indexType;

	/**
	 * @var array
	 */
	private $maxShardsPerNode;

	/**
	 * @param Index $index
	 * @param string $indexType
	 * @param array $maxShardsPerNode
	 * @param Maintenance $out
	 */
	public function __construct( Index $index, $indexType, array $maxShardsPerNode, Maintenance $out = null ) {
		parent::__construct( $out );

		$this->index = $index;
		$this->indexType = $indexType;
		$this->maxShardsPerNode = $maxShardsPerNode;
	}

	/**
	 * @return Status
	 */
	public function validate() {
		$this->outputIndented( "\tValidating max shards per node..." );
		$settings = $this->index->getSettings()->get();
		// Elasticsearch uses negative numbers or an unset value to represent unlimited.  We use the word 'unlimited'
		// because that is easier to read.
		$actualMaxShardsPerNode = isset( $settings[ 'routing' ][ 'allocation' ][ 'total_shards_per_node' ] ) ?
			$settings[ 'routing' ][ 'allocation' ][ 'total_shards_per_node' ] : 'unlimited';
		$actualMaxShardsPerNode = $actualMaxShardsPerNode < 0 ? 'unlimited' : $actualMaxShardsPerNode;
		$expectedMaxShardsPerNode = isset( $this->maxShardsPerNode[ $this->indexType ] ) ?
			$this->maxShardsPerNode[ $this->indexType ] : 'unlimited';
		if ( $actualMaxShardsPerNode == $expectedMaxShardsPerNode ) {
			$this->output( "ok\n" );
		} else {
			$this->output( "is $actualMaxShardsPerNode but should be $expectedMaxShardsPerNode..." );
			$expectedMaxShardsPerNode = $expectedMaxShardsPerNode === 'unlimited' ? -1 : $expectedMaxShardsPerNode;
			$this->index->getSettings()->set( array(
				'routing.allocation.total_shards_per_node' => $expectedMaxShardsPerNode
			) );
			$this->output( "corrected\n" );
		}

		return Status::newGood();
	}
}
