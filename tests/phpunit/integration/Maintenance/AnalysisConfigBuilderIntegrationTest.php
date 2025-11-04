<?php

namespace CirrusSearch\Maintenance;

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
		$serverVersion = [ 'distribution' => 'opensearch' ];
		$builder = new AnalysisConfigBuilder(
			'en', $serverVersion, [], new HashSearchConfig( [ 'CirrusSearchSimilarityProfile' => 'default' ] )
		);
		$sim = $builder->buildSimilarityConfig();
		$this->assertSame( [ 'custom' => [] ], $sim['custom'] );
	}
}
