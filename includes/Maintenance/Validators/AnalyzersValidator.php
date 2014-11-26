<?php

namespace CirrusSearch\Maintenance\Validators;

use CirrusSearch\Maintenance\AnalysisConfigBuilder;
use CirrusSearch\Maintenance\Maintenance;
use Elastica\Index;

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

	public function validate() {
		$this->outputIndented( "Validating analyzers..." );
		$settings = $this->index->getSettings()->get();
		$requiredAnalyzers = $this->analysisConfigBuilder->buildConfig();
		if ( $this->checkConfig( $settings[ 'analysis' ], $requiredAnalyzers ) ) {
			$this->output( "ok\n" );
		} else {
			$this->output( "cannot correct\n" );
			return false;
		}

		return true;
	}
}
