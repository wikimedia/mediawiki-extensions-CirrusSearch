<?php

namespace CirrusSearch;

use CirrusSearch\Test\DummyConnection;
use LogicException;
use MediaWiki\Config\ConfigException;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityValue;
use PHPUnit\Framework\Assert;
use Wikimedia\Stats\StatsFactory;

/**
 * @covers \CirrusSearch\ElasticsearchIntermediary
 */
class ElasticsearchIntermediaryTest extends CirrusIntegrationTestCase {

	public static function provideTestTimeouts() {
		return [
			'simple' => [
				[
					CirrusConfigNames::ClientSideSearchTimeout => [ 'test' => 1 ],
					CirrusConfigNames::SearchShardTimeout => [ 'test' => '2s' ],
				],
				'test', 1, '2s',
			],
			'fallback to defaults' => [
				[
					CirrusConfigNames::ClientSideSearchTimeout => [ 'default' => 1 ],
					CirrusConfigNames::SearchShardTimeout => [ 'default' => '2s' ],
				],
				'test', 1, '2s',
			],
		];
	}

	/**
	 * @dataProvider provideTestTimeouts
	 */
	public function testTimeouts( array $config, $searchType, $expectedClientTimeout, $expectedShardTimeout ) {
		$connection = new DummyConnection( new HashSearchConfig( $config ) );
		$intermediary = new class( $connection ) extends ElasticsearchIntermediary {
			public function __construct( Connection $connection ) {
				parent::__construct( $connection, new UserIdentityValue( 0, '' ) );
			}

			protected function newLog(
				$description, $queryType, array $extra = [], ?array $namespaces = [], ?string $poolCounterKey = null
			) {
				throw new LogicException( "Not supposed to be called" );
			}

			public function assertions( $searchType, $expectedClientTimeout, $expectedShardTimeout ) {
				Assert::assertEquals( $expectedShardTimeout, $this->getTimeout( $searchType ) );
				Assert::assertEquals( $expectedClientTimeout, $this->getClientTimeout( $searchType ) );
			}
		};
		$intermediary->assertions( $searchType, $expectedClientTimeout, $expectedShardTimeout );
	}

	public function testTimeoutMisconfiguration() {
		$this->expectException( ConfigException::class );
		$this->testTimeouts( [], 'test', 1, '1s' );
	}

	public function testConcludeRequestTwice() {
		$connection = new DummyConnection( new HashSearchConfig( [] ) );
		$intermediary = new class( $connection ) extends ElasticsearchIntermediary {
			public function __construct( Connection $connection ) {
				parent::__construct( $connection );
			}

			protected function newLog(
				$description, $queryType, array $extra = [], ?array $namespaces = null, ?string $poolCounterKey = null
			) {
				throw new LogicException( "Not supposed to be called" );
			}
		};

		$log = $this->createMock( RequestLog::class );
		$log->method( 'getTookMs' )->willReturn( 1.0 );
		$log->method( 'getLogVariables' )->willReturn( [] );
		$log->method( 'getRequests' )->willReturn( [] );
		$log->method( 'getQueryType' )->willReturn( 'search_type' );
		$intermediary->start( $log );
		$intermediary->success();
		$intermediary->failure();
		// Basically assert the mistaken second failure call still "worked"
		$this->assertTrue( true );
	}

	public static function provideDefaultMetricLabels() {
		$preRequestLabel = [ 'pool' => 'my_pool', 'query_type' => 'my_query_type', 'weighted_tags' => 'no' ];
		$commonLabels = $preRequestLabel + [ 'search_cluster' => 'default' ];
		yield 'success' => [ false, false,
			[
				'pool_counter_seconds' => [
					'count' => 1,
					'labels' => $preRequestLabel + [ 'status' => 'success' ]
				],
				'request_time_seconds' => [
					'count' => 1,
					'labels' => $commonLabels + [ 'partial_results' => 'false', 'error_type' => 'none' ]
				],
				'partial_results_total' => [
					'count' => 0,
					'labels' => $commonLabels
				],
				'backend_failures_total' => [
					'count' => 0,
					'labels' => $commonLabels
				],
			]
		];
		yield 'partial' => [ false, true,
			[
				'pool_counter_seconds' => [
					'count' => 1,
					'labels' => $preRequestLabel + [ 'status' => 'success' ]
				],
				'request_time_seconds' => [
					'count' => 1,
					'labels' => $commonLabels + [ 'partial_results' => 'true', 'error_type' => 'none' ]
				],
				'partial_results_total' => [
					'count' => 1,
					'labels' => $commonLabels
				],
				'backend_failures_total' => [
					'count' => 0,
					'labels' => $commonLabels
				],
			]
		];
		yield 'error' => [
			true,
			false,
			[
				'pool_counter_seconds' => [
					'count' => 1,
					'labels' => $preRequestLabel + [ 'status' => 'success' ]
				],
				'request_time_seconds' => [
					'count' => 1,
					'labels' => $commonLabels + [ 'partial_results' => 'false', 'error_type' => 'unknown' ]
				],
				'partial_results_total' => [
					'count' => 0,
					'labels' => $commonLabels
				],
				'backend_failures_total' => [
					'count' => 1,
					'labels' => $commonLabels + [ 'type' => 'unknown' ]
				],
			]
		];
	}

	/**
	 * @covers \CirrusSearch\BaseRequestLog
	 * @covers \CirrusSearch\ElasticsearchIntermediary
	 * @dataProvider provideDefaultMetricLabels
	 */
	public function testRequestMetricsAreLabelled( bool $error, bool $partial, array $expectedMetrics ) {
		$statsHelper = StatsFactory::newUnitTestingHelper();
		$this->setService( 'StatsFactory', $statsHelper->getStatsFactory() );

		$connection = new DummyConnection( $this->newHashSearchConfig( [] ) );
		$user = $this->createMock( UserIdentity::class );
		$intermediary = new class( $connection, $user ) extends ElasticsearchIntermediary {
			public function __construct( Connection $connection, UserIdentity $user ) {
				parent::__construct( $connection, $user );
			}

			protected function newLog(
				$description, $queryType, array $extra = [], ?array $namespaces = []
			) {
				return new class( $description, $queryType, $extra, $this->getMetricLabels() ) extends BaseRequestLog {
					public function getElasticTookMs() {
						return 1.0;
					}

					public function isCachedResponse() {
						return false;
					}

					public function getLogVariables() {
						return [];
					}

					public function getRequests() {
						return [];
					}
				};
			}

			public function doSomeSearch( bool $error, bool $partial ): void {
				$log = $this->newLog( 'some search', 'my_query_type' );
				Util::doPoolCounterWork(
					'CirrusSearch-my_pool',
					$this->user,
					function () use ( $log, $error, $partial ) {
						$this->start( $log );
						if ( $error ) {
							$this->failure();
						} else {
							$this->success( null, null, $partial );
						}
					},
					null,
					$log
				);
			}
		};

		$intermediary->doSomeSearch( $error, $partial );

		// The injected service collects every metric emitted during the test, unrelated
		// MediaWiki ones included, so select the metric under test instead of asserting
		// on everything that was emitted.
		$statsHelper->withComponent( 'CirrusSearch' );
		$this->assertNotEmpty( $expectedMetrics );
		foreach ( $expectedMetrics as $name => $metric ) {
			$selector = implode(
				',',
				array_map( static fn ( $k ): string => "$k={$metric['labels'][$k]}", array_keys( $metric['labels'] ) )
			);
			if ( $metric['count'] === 0 ) {
				try {
					$statsHelper->count( "$name{{$selector}}" );
					$this->fail( "Selector $name{{$selector}} should not match" );
				} catch ( \OutOfBoundsException | \OutOfRangeException $e ) {
				}
			} else {
				$this->assertSame( $metric['count'], $statsHelper->count( "$name{{$selector}}" ) );
			}
		}
	}
}
