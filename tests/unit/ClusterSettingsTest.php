<?php

namespace CirrusSearch;

class ClusterSettingsTest extends \PHPUnit_Framework_TestCase {

	public static function provideShardCount() {
		return array(
			'Handles per-index shard counts' => array(
				array( 'general' => 7 ),
				'eqiad',
				'general',
				7,
			),

			'Handles per-cluster shard counts' => array(
				array( 'content' => 6, 'eqiad' => array( 'content' => 9 ) ),
				'eqiad',
				'content',
				9,
			),
		);
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
		return array(
			'Simple replica config returns exact setting ' => array(
				'0-2',
				'eqiad',
				'content',
				'0-2',
			),

			'Accepts array for replica config' => array(
				array( 'content' => '1-2' ),
				'eqiad',
				'content',
				'1-2',
			),

			'Accepts per-cluster replica config' => array(
				array( 'content' => '1-2', 'eqiad' => array( 'content' => '2-3' ) ),
				'eqiad',
				'content',
				'2-3'
			),
		);
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
}
