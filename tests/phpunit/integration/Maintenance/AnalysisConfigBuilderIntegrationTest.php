<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\CirrusConfigNames;
use CirrusSearch\CirrusIntegrationTestCase;
use CirrusSearch\HashSearchConfig;

class AnalysisConfigBuilderIntegrationTest extends CirrusIntegrationTestCase {

	/**
	 * @covers \CirrusSearch\Maintenance\AnalysisConfigBuilder::__construct
	 */
	public function testSimilarityHook() {
		$this->setTemporaryHook( 'CirrusSearchSimilarityConfig', static function ( &$config ) {
			$config['custom'] = [ 'custom' => [] ];
		} );
		$builder = new AnalysisConfigBuilder(
			'en', [], new HashSearchConfig( [ CirrusConfigNames::SimilarityProfile => 'default' ] ) );
		$sim = $builder->buildSimilarityConfig();
		$this->assertSame( [ 'custom' => [] ], $sim['custom'] );
	}
}
