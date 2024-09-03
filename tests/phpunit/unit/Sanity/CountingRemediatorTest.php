<?php

namespace CirrusSearch\Sanity;

use CirrusSearch\CirrusTestCase;
use Psr\Log\NullLogger;
use Wikimedia\Stats\Metrics\BaseMetric;
use Wikimedia\Stats\Metrics\CounterMetric;
use WikiPage;

/**
 * @covers \CirrusSearch\Sanity\CountingRemediator
 */
class CountingRemediatorTest extends CirrusTestCase {

	public function testRedirectInIndex() {
		$baseMetric = new BaseMetric( '', 'testMetric' );
		$metric = new CounterMetric( $baseMetric, new NullLogger() );

		$buffer = new BufferedRemediator();
		$remediator = new CountingRemediator(
			$buffer,
			static function ( string $problem ) use ( $metric ) {
				$metric->setLabel( "problem", $problem );
				return $metric;
			}
		);

		$page = $this->createNoOpMock( WikiPage::class );
		$this->assertCount( 0, $baseMetric->getSamples() );
		$remediator->redirectInIndex( '42', $page, 'content' );
		$this->assertCount( 1, $baseMetric->getSamples() );
		$this->assertArrayContains( [ "problem" ], $baseMetric->getLabelKeys() );
		$this->assertArrayContains( [ "redirectInIndex" ], $baseMetric->getLabelValues() );
	}
}
