<?php

namespace CirrusSearch\Maintenance\Validators;

use CirrusSearch\Maintenance\Maintenance;
use Elastica\Index;

class NumberOfShardsValidator extends Validator {
	/**
	 * @var Index
	 */
	private $index;

	/**
	 * @var int
	 */
	protected $shardCount;

	/**
	 * @param Index $index
	 * @param int $shardCount
	 * @param Maintenance $out
	 */
	public function __construct( Index $index, $shardCount, Maintenance $out = null ) {
		parent::__construct( $out );

		$this->index = $index;
		$this->shardCount = $shardCount;
	}

	public function validate() {
		$this->outputIndented( "\tValidating number of shards..." );
		$settings = $this->index->getSettings()->get();
		$actualShardCount = $settings['number_of_shards'];
		if ( $actualShardCount == $this->shardCount ) {
			$this->output( "ok\n" );
		} else {
			$this->output( "is $actualShardCount but should be " . $this->shardCount . "...cannot correct!\n" );
			return false;
		}

		return true;
	}
}
