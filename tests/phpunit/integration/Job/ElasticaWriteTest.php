<?php

namespace CirrusSearch\Job;

use CirrusSearch\CirrusIntegrationTestCase;
use CirrusSearch\ClusterSettings;
use CirrusSearch\HashSearchConfig;
use Liuggio\StatsdClient\Factory\StatsdDataFactoryInterface;
use MediaWiki\Utils\MWTimestamp;

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

	public function testReportUpdateLog() {
		$statsD = $this->createMock( StatsdDataFactoryInterface::class );
		$statsD->expects( $this->once() )->method( 'timing' )->with( "CirrusSearch.my_cluster.updates.all.lag.my_update_kind", 10 );
		$myJob = new ElasticaWrite( [
			CirrusTitleJob::UPDATE_KIND => "my_update_kind",
			CirrusTitleJob::ROOT_EVENT_TIME => 0
		] );
		MWTimestamp::setFakeTime( 10 );
		$myJob->reportUpdateLag( "my_cluster", $statsD );
	}

	public function testNoLagReportedWithoutEventTime() {
		$statsD = $this->createMock( StatsdDataFactoryInterface::class );
		$statsD->expects( $this->never() )->method( 'timing' );
		$myJob = new ElasticaWrite( [] );
		$myJob->reportUpdateLag( "my_cluster", $statsD );
	}

}
