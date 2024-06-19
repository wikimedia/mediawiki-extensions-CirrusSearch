<?php

namespace CirrusSearch\Job;

use CirrusSearch\CirrusIntegrationTestCase;
use CirrusSearch\ClusterSettings;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\UpdateGroup;
use MediaWiki\Utils\MWTimestamp;
use Wikimedia\Stats\Metrics\TimingMetric;
use Wikimedia\Stats\StatsFactory;

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
			$job = ElasticaWrite::build( $settings, UpdateGroup::PAGE, 'unreferenced', [], [] );
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
		$stats = $this->createMock( StatsFactory::class );

		$timing = $this->createMock( TimingMetric::class );

		$stats->expects( $this->once() )
			->method( 'getTiming' )
			->with( "cirrus_search_update_lag" )
			->willReturn( $timing );
		$timing->expects( $this->exactly( 2 ) )
			->method( 'setLabel' )
			->willReturnMap( [
				[ 'cluster', 'my_cluster', $timing ],
				[ 'update_kind', 'my_update_kind', $timing ] ] );
		$timing->expects( $this->once() )
			->method( 'copyToStatsdAt' )
			->with( "CirrusSearch.my_cluster.updates.all.lag.my_update_kind" )
			->willReturn( $timing );
		$timing->expects( $this->once() )
			->method( 'observe' )
			->with( 10 );

		$myJob = new ElasticaWrite( [
			CirrusTitleJob::UPDATE_KIND => "my_update_kind",
			CirrusTitleJob::ROOT_EVENT_TIME => 0
		] );
		MWTimestamp::setFakeTime( 10 );
		$myJob->reportUpdateLag( "my_cluster", $stats );
	}

	public function testNoLagReportedWithoutEventTime() {
		$stats = $this->createMock( StatsFactory::class );
		$stats->expects( $this->never() )->method( 'getTiming' );
		$myJob = new ElasticaWrite( [] );
		$myJob->reportUpdateLag( "my_cluster", $stats );
	}

}
