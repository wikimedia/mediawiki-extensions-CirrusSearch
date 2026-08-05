<?php

namespace CirrusSearch\Maintenance\Validators;

use CirrusSearch\Maintenance\Printer;
use CirrusSearch\ReplicaCount;
use Elastica\Index;
use MediaWiki\Status\Status;

class ReplicaCountValidator extends Validator {
	/**
	 * @var Index
	 */
	private $index;

	/**
	 * @var ReplicaCount
	 */
	protected $replicaCount;

	/**
	 * @param Index $index
	 * @param ReplicaCount $replicaCount
	 * @param Printer|null $out
	 */
	public function __construct( Index $index, ReplicaCount $replicaCount, ?Printer $out = null ) {
		parent::__construct( $out );

		$this->index = $index;
		$this->replicaCount = $replicaCount;
	}

	/**
	 * @return Status
	 */
	public function validate() {
		$this->outputIndented( "\tValidating replica count..." );
		$settings = $this->index->getSettings()->get();
		if ( $this->replicaCount->matchesIndexSettings( $settings ) ) {
			$this->output( "ok\n" );
		} else {
			$actualReplicaCount = ReplicaCount::fromIndexSettings( $settings );
			$this->output( "is $actualReplicaCount but should be " . $this->replicaCount . '...' );
			$this->index->getSettings()->set( $this->replicaCount->toUpdateSettings() );
			$this->output( "corrected\n" );
		}

		return Status::newGood();
	}
}
