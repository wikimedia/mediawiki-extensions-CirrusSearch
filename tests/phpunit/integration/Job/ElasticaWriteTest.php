<?php

namespace CirrusSearch\Job;

use CirrusSearch\CirrusIntegrationTestCase;
use CirrusSearch\ClusterSettings;
use CirrusSearch\HashSearchConfig;

/**
 * @covers \CirrusSearch\Job\ElasticaWrite
 */
class ElasticaWriteTest extends CirrusIntegrationTestCase {

	public function testExplicitPartitioningParameter() {
		$cluster = 'arbitrary-name';
		$config = new HashSearchConfig( [
			'CirrusSearchWriteIsolateClusters' => [ $cluster ],
			'CirrusSearchElasticaWritePartitionCounts' => [
				$cluster => 2,
			],
		] );
		$settings = new ClusterSettings( $config, $cluster );
		// actual partition key value is random, run a few times and assert we
		// get multiple values back.
		$seen = [];
		for ( $i = 0; $i < 100; $i++ ) {
			$job = ElasticaWrite::build( $settings, 'unreferenced', [], [] );
			$seen[$job->params['jobqueue_partition']] = true;
		}
		ksort( $seen );
		$this->assertEquals(
			[ "{$cluster}-0", "{$cluster}-1" ],
			array_keys( $seen ),
			'Saw expected set of partitioning keys'
		);
	}
}
