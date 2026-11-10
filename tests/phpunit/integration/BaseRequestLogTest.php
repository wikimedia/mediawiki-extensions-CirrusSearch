<?php

namespace CirrusSearch;

use Elastica\Client;
use Wikimedia\Assert\PreconditionException;
use Wikimedia\Stats\StatsFactory;

/**
 * @group CirrusSearch
 * @covers \CirrusSearch\BaseRequestLog
 */
class BaseRequestLogTest extends CirrusIntegrationTestCase {

	public static function provideLogOrder() {
		yield 'logs without metric labels first' => [ [ 'bulk', 'completion', 'search' ] ];
		yield 'logs with metric labels first' => [ [ 'search', 'bulk', 'completion' ] ];
	}

	/**
	 * @dataProvider provideLogOrder
	 */
	public function testLogTypesShareMetricLabelKeys( array $order ) {
		$statsHelper = StatsFactory::newUnitTestingHelper();
		$this->setService( 'StatsFactory', $statsHelper->getStatsFactory() );
		$client = $this->createMock( Client::class );
		$newLogs = [
			// The bulk and completion logs get no metric labels. They must use the default values.
			'bulk' => static fn () => new BulkUpdateRequestLog( $client, 'bulk', 'send_data_write' ),
			'completion' => static fn () => new CompletionRequestLog( 'completion', 'comp_suggest' ),
			'search' => static fn () => new SearchRequestLog(
				$client, 'search', 'full_text', [], null, [ 'weighted_tags' => 'yes' ]
			),
		];

		// All the logs use the same stats factory, as in one PHP request.
		foreach ( $order as $type ) {
			$log = $newLogs[$type]();
			$log->poolCounterStartAcquire( 'CirrusSearch-Search', 'key' );
			$log->poolCounterEndAcquire();
			$log->start();
			$log->finish( 'default', null, false );
		}

		$statsHelper->withComponent( 'CirrusSearch' );
		foreach ( [ 'pool_counter_seconds', 'request_time_seconds' ] as $name ) {
			$this->assertSame( 3, $statsHelper->count( $name ), "All samples of $name are recorded" );
			$this->assertSame( 1, $statsHelper->count( "{$name}{query_type=send_data_write,weighted_tags=no,interwiki=no}" ) );
			$this->assertSame( 1, $statsHelper->count( "{$name}{query_type=comp_suggest,weighted_tags=no,interwiki=no}" ) );
			$this->assertSame( 1, $statsHelper->count( "{$name}{query_type=full_text,weighted_tags=yes,interwiki=no}" ) );
		}
	}

	public function testUnknownMetricLabelIsRejected() {
		$this->expectException( PreconditionException::class );
		$this->expectExceptionMessage( '[pool]' );
		new CompletionRequestLog( 'completion', 'comp_suggest', [], null, [ 'pool' => 'Search' ] );
	}
}
