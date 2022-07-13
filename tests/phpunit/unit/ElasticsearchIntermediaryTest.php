<?php

namespace CirrusSearch;

use CirrusSearch\Test\DummyConnection;
use MediaWiki\User\UserIdentityValue;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;

/**
 * @covers \CirrusSearch\ElasticsearchIntermediary
 */
class ElasticsearchIntermediaryTest extends CirrusTestCase {

	public function provideTestTimeouts() {
		return [
			'simple' => [
				[
					'CirrusSearchClientSideSearchTimeout' => [ 'test' => 1 ],
					'CirrusSearchSearchShardTimeout' => [ 'test' => '2s' ],
				],
				'test', 1, '2s',
			],
			'fallback to defaults' => [
				[
					'CirrusSearchClientSideSearchTimeout' => [ 'default' => 1 ],
					'CirrusSearchSearchShardTimeout' => [ 'default' => '2s' ],
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
		$requestLogger = $this->createMock( RequestLogger::class );
		$user = new UserIdentityValue( 0, '' );
		$slowSeconds = null;
		$extraBackendLatency = 0;
		$intermediary = new class(
			$connection,
			$user,
			$slowSeconds,
			$extraBackendLatency,
			$requestLogger
		) extends ElasticsearchIntermediary {
			public function __construct(
				Connection $connection,
				$user,
				$slowSeconds,
				$extraBackendLatency,
				$requestLogger
			) {
				parent::__construct( $connection, $user, $slowSeconds, $extraBackendLatency, $requestLogger );
			}

			protected function newLog( $description, $queryType, array $extra = [] ) {
				throw new AssertionFailedError( "Not supposed to be called" );
			}

			public function assertions( $searchType, $expectedClientTimeout, $expectedShardTimeout ) {
				Assert::assertEquals( $expectedShardTimeout, $this->getTimeout( $searchType ) );
				Assert::assertEquals( $expectedClientTimeout, $this->getClientTimeout( $searchType ) );
			}
		};
		$intermediary->assertions( $searchType, $expectedClientTimeout, $expectedShardTimeout );
	}

	public function testTimeoutMisconfiguration() {
		$this->expectException( \ConfigException::class );
		$this->testTimeouts( [], 'test', 1, '1s' );
	}

	public function testConcludeRequestTwice() {
		$connection = new DummyConnection( new HashSearchConfig( [] ) );
		$requestLogger = $this->createMock( RequestLogger::class );
		$user = new UserIdentityValue( 0, '' );
		$slowSeconds = null;
		$extraBackendLatency = 0;
		$intermediary = new class(
			$connection,
			$user,
			$slowSeconds,
			$extraBackendLatency,
			$requestLogger
		) extends ElasticsearchIntermediary {
			public function __construct(
				Connection $connection,
				$user,
				$slowSeconds,
				$extraBackendLatency,
				$requestLogger
			) {
				parent::__construct( $connection, $user, $slowSeconds, $extraBackendLatency, $requestLogger );
			}

			protected function newLog( $description, $queryType, array $extra = [] ) {
				throw new AssertionFailedError( "Not supposed to be called" );
			}
		};

		$log = $this->getMockBuilder( RequestLog::class )->getMock();
		$log->method( 'getLogVariables' )->willReturn( [] );
		$log->method( 'getRequests' )->willReturn( [] );
		$intermediary->start( $log );
		$intermediary->success();
		$intermediary->failure();
		// Basically assert the mistaken second failure call still "worked"
		$this->assertTrue( true );
	}
}
