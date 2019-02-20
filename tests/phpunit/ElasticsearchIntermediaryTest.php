<?php

namespace CirrusSearch;

use CirrusSearch\Test\DummyConnection;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;

/**
 * @covers \CirrusSearch\ElasticsearchIntermediary
 */
class ElasticsearchIntermediaryTest extends CirrusTestCase {

	public function profileTestTimeouts() {
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
	 * @dataProvider profileTestTimeouts
	 * @param array $config
	 * @param $searchType
	 * @param $expectedClientTimeout
	 * @param $expectedShardTimeout
	 */
	public function testTimeouts( array $config, $searchType, $expectedClientTimeout, $expectedShardTimeout ) {
		$connection = new DummyConnection( new HashSearchConfig( $config ) );
		$intermediary = new class( $connection ) extends ElasticsearchIntermediary {
			public function __construct( Connection $connection ) {
				parent::__construct( $connection );
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
}
