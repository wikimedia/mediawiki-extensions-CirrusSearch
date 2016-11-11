<?php

namespace CirrusSearch;

/**
 * @group CirrusSearch
 */
class ClusterSettingsTest extends CirrusTestCase {

	public static function provideShardCount() {
		return [
			'Handles per-index shard counts' => [
				[ 'general' => 7 ],
				'eqiad',
				'general',
				7,
			],

			'Handles per-cluster shard counts' => [
				[ 'content' => 6, 'eqiad' => [ 'content' => 9 ] ],
				'eqiad',
				'content',
				9,
			],
		];
	}

	/**
	 * @dataProvider provideShardCount
	 */
	public function testShardCount( $shardCounts, $cluster, $indexType, $expect ) {
		$config = $this->getMockBuilder( 'CirrusSearch\SearchConfig' )
			->disableOriginalConstructor()
			->getMock();
		$config->expects( $this->any() )
			->method( 'get' )
			->with( 'CirrusSearchShardCount' )
			->will( $this->returnValue( $shardCounts ) );

		$settings = new ClusterSettings( $config, $cluster );
		$this->assertEquals( $expect, $settings->getShardCount( $indexType ) );
	}

	public static function provideReplicaCounts() {
		return [
			'Simple replica config returns exact setting ' => [
				'0-2',
				'eqiad',
				'content',
				'0-2',
			],

			'Accepts array for replica config' => [
				[ 'content' => '1-2' ],
				'eqiad',
				'content',
				'1-2',
			],

			'Accepts per-cluster replica config' => [
				[ 'content' => '1-2', 'eqiad' => [ 'content' => '2-3' ] ],
				'eqiad',
				'content',
				'2-3'
			],
		];
	}

	/**
	 * @dataProvider provideReplicaCounts
	 */
	public function testReplicaCount( $replicas, $cluster, $indexType, $expect) {
		$config = $this->getMockBuilder( 'CirrusSearch\SearchConfig' )
			->disableOriginalConstructor()
			->getMock();
		$config->expects( $this->any() )
			->method( 'get' )
			->with( 'CirrusSearchReplicas' )
			->will( $this->returnValue( $replicas ) );

		$settings = new ClusterSettings( $config, $cluster );
		$this->assertEquals( $expect, $settings->getReplicaCount( $indexType ) );
	}

	public static function provideDropDelayedJobsAfter() {
		return [
			'Simple integer timeout is returned directly' => [
				60, 'eqiad', 60
			],
			'Can set per-cluster timeout' => [
				[ 'eqiad' => 99, 'labsearch' => 42 ],
				'labsearch',
				42
			],
		];
	}

	/**
	 * @dataProvider provideDropDelayedJobsAfter()
	 */
	public function testDropDelayedJobsAfter( $timeout, $cluster, $expect ) {
		$config = $this->getMockBuilder( 'CirrusSearch\SearchConfig' )
			->disableOriginalConstructor()
			->getMock();
		$config->expects( $this->any() )
			->method( 'get' )
			->with( 'CirrusSearchDropDelayedJobsAfter' )
			->will( $this->returnValue( $timeout ) );

		$settings = new ClusterSettings( $config, $cluster );
		$this->assertEquals( $expect, $settings->getDropDelayedJobsAfter() );
	}
}
