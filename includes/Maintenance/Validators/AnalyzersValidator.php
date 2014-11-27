<?php

namespace CirrusSearch\Maintenance\Validators;

use CirrusSearch\Maintenance\AnalysisConfigBuilder;
use CirrusSearch\Maintenance\Maintenance;
use Elastica\Index;
use RawMessage;
use Status;

class AnalyzersValidator extends Validator {
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
	 * @param Maintenance $out
	 */
	public function __construct( Index $index, AnalysisConfigBuilder $analysisConfigBuilder, Maintenance $out = null ) {
		parent::__construct( $out );

		$this->index = $index;
		$this->analysisConfigBuilder = $analysisConfigBuilder;
	}

	/**
	 * @return Status
	 */
	public function validate() {
		$this->outputIndented( "Validating analyzers..." );
		$settings = $this->index->getSettings()->get();
		$requiredAnalyzers = $this->analysisConfigBuilder->buildConfig();
		if ( $this->checkConfig( $settings[ 'analysis' ], $requiredAnalyzers ) ) {
			$this->output( "ok\n" );
		} else {
			$this->output( "cannot correct\n" );
			return Status::newFatal( new RawMessage(
				"This script encountered an index difference that requires that the index be\n" .
				"copied, indexed to, and then the old index removed. Re-run this script with the\n" .
				"--reindexAndRemoveOk --indexIdentifier=now parameters to do this." ) );
		}

		return Status::newGood();
	}
}
