<?php

namespace CirrusSearch;

/**
 * @group CirrusSearch
 * @covers \CirrusSearch\ClusterSettings
 */
class ClusterSettingsTest extends CirrusTestCase {

	public static function provideShardCount() {
		return [
			'Handles per-index shard counts' => [
				[ 'general' => 7 ],
				'dc-foo',
				'general',
				7,
			],

			'Handles per-cluster shard counts' => [
				[ 'content' => 6, 'dc-foo' => [ 'content' => 9 ] ],
				'dc-foo',
				'content',
				9,
			],
		];
	}

	public function testReturnsProvidedClusterName() {
		$cluster = 'some-name';
		$settings = new ClusterSettings( new HashSearchConfig( [] ), $cluster );
		$this->assertEquals( $cluster, $settings->getName() );
	}

	/**
	 * @dataProvider provideShardCount
	 */
	public function testShardCount( $shardCounts, $cluster, $indexSuffix, $expect ) {
		$config = $this->getMockBuilder( SearchConfig::class )
			->disableOriginalConstructor()
			->getMock();
		$config->method( 'get' )
			->with( 'CirrusSearchShardCount' )
			->willReturn( $shardCounts );

		$settings = new ClusterSettings( $config, $cluster );
		$this->assertEquals( $expect, $settings->getShardCount( $indexSuffix ) );
	}

	public static function provideReplicaCounts() {
		return [
			'Simple replica config returns exact setting ' => [
				'0-2',
				'dc-foo',
				'content',
				'0-2',
			],

			'Accepts array for replica config' => [
				[ 'content' => '1-2' ],
				'dc-foo',
				'content',
				'1-2',
			],

			'Accepts per-cluster replica config' => [
				[ 'content' => '1-2', 'dc-foo' => [ 'content' => '2-3' ] ],
				'dc-foo',
				'content',
				'2-3'
			],
		];
	}

	/**
	 * @dataProvider provideReplicaCounts
	 */
	public function testReplicaCount( $replicas, $cluster, $indexSuffix, $expect ) {
		$config = $this->getMockBuilder( SearchConfig::class )
			->disableOriginalConstructor()
			->getMock();
		$config->method( 'get' )
			->with( 'CirrusSearchReplicas' )
			->willReturn( $replicas );

		$settings = new ClusterSettings( $config, $cluster );
		$this->assertEquals( $expect, $settings->getReplicaCount( $indexSuffix ) );
	}

	public static function provideMaxShardsPerNode() {
		return [
			'empty configuration' => [
				'maxShardsPerNode' => [],
				'cluster' => 'default',
				'indexSuffix' => 'content',
				'expect' => -1,
			],
			'explicitly unbounded' => [
				'maxShardsPerNode' => [ 'content' => 1, 'general' => 'unlimited' ],
				'cluster' => 'default',
				'indexSuffix' => 'general',
				'expect' => -1,
			],
			'defined for index type' => [
				'maxShardsPerNode' => [ 'content' => 1 ],
				'cluster' => 'default',
				'indexSuffix' => 'content',
				'expect' => 1,
			],
			'defined for other index type' => [
				'maxShardsPerNode' => [ 'general' => 1 ],
				'cluster' => 'default',
				'indexSuffix' => 'content',
				'expect' => -1,
			],
			'defined per cluster (1/2)' => [
				'maxShardsPerNode' => [
					'cluster1' => [ 'content' => 3 ],
					'cluster2' => [ 'content' => 1 ],
				],
				'cluster' => 'cluster1',
				'indexSuffix' => 'content',
				'expect' => 3,
			],

			'defined per cluster (2/2)' => [
				'maxShardsPerNode' => [
					'cluster1' => [ 'content' => 3 ],
					'cluster2' => [ 'content' => 1 ],
				],
				'cluster' => 'cluster2',
				'indexSuffix' => 'content',
				'expect' => 1,
			],
			'mixed per-cluster and global defaults (1/2)' => [
				'maxShardsPerNode' => [
					'cluster1' => [ 'content' => 3 ],
					'content' => 1,
				],
				'cluster' => 'cluster1',
				'indexSuffix' => 'content',
				'expect' => 3,
			],
			'mixed per-cluster and global defaults (2/2)' => [
				'maxShardsPerNode' => [
					// Fully defined, with cluster + indexName, must take precedence
					'cluster1' => [ 'content' => 3 ],
					'content' => 1,
				],
				'cluster' => 'cluster1',
				'indexSuffix' => 'content',
				'expect' => 3,
			],
		];
	}

	/**
	 * @dataProvider provideMaxShardsPerNode
	 */
	public function testGetMaxShardsPerNode( $maxShardsPerNode, $cluster, $indexSuffix, $expect ) {
		$config = $this->getMockBuilder( SearchConfig::class )
			->disableOriginalConstructor()
			->getMock();
		$config->method( 'get' )
			->with( 'CirrusSearchMaxShardsPerNode' )
			->willReturn( $maxShardsPerNode );

		$settings = new ClusterSettings( $config, $cluster );
		$this->assertEquals( $expect, $settings->getMaxShardsPerNode( $indexSuffix ) );
	}

	public static function provideIsPrivate() {
		return [
			'null allows all' => [
				'expected' => true,
				'cluster' => 'dc.a',
				'privateClusters' => null,
			],
			'listed clusters are private' => [
				'expected' => true,
				'cluster' => 'dc.a',
				'privateClusters' => [ 'dc.a', 'dc.b' ],
			],
			'unlisted clusters are not private' => [
				'expected' => false,
				'cluster' => 'unk',
				'privateClusters' => [ 'dc.a', 'dc.b' ],
			],
		];
	}

	/**
	 * @dataProvider provideIsPrivate
	 */
	public function testIsPrivate( $expected, $cluster, $privateClusters ) {
		$config = new HashSearchConfig( [
			'CirrusSearchPrivateClusters' => $privateClusters,
		] );
		$settings = new ClusterSettings( $config, $cluster );
		$this->assertEquals( $expected, $settings->isPrivateCluster() );
	}

	public function provideIsolation() {
		return [
			'null value isolates everything' => [
				'expected' => true,
				'cluster' => 'arbitrary-name',
				'isolateClusters' => null
			],
			'When configured with no named clusters unnamed clusters are not isolated' => [
				'expected' => false,
				'cluster' => 'unnamed',
				'isolateClusters' => []
			],
			'When configured with named clusters unnamed clusters are not isolated' => [
				'expected' => false,
				'cluster' => 'unnamed',
				'isolateClusters' => [ 'arbitrary-name' ]
			],
			'Named clusters are write isolated' => [
				'expected' => true,
				'cluster' => 'arbitrary-name',
				'isolateClusters' => [ 'arbitrary-name' ]
			],
		];
	}

	/**
	 * @dataProvider provideIsolation
	 */
	public function testIsIsolated( $expected, $cluster, $isolateClusters ) {
		$config = new HashSearchConfig( [
			'CirrusSearchWriteIsolateClusters' => $isolateClusters,
		] );
		$settings = new ClusterSettings( $config, $cluster );
		$this->assertEquals( $expected, $settings->isIsolated() );
	}

	public function provideElasticaWritePartitionCount() {
		return [
			'unnamed clusters default to 1' => [
				'expected' => 1,
				'cluster' => 'unnamed-cluster',
				'partitionCount' => []
			],
			'unnamed clusters default to 1 (part duex)' => [
				'expected' => 1,
				'cluster' => 'unnamed-cluster',
				'partitionCount' => [ 'arbitrary-cluster' => 4 ]
			],
			'named clusters receive provided value' => [
				'expected' => 3,
				'cluster' => 'named-cluster',
				'partitionCount' => [ 'named-cluster' => 3 ],
			],
		];
	}

	/**
	 * @dataProvider provideElasticaWritePartitionCount
	 */
	public function testElasticaWritePartitionCount( $expected, $cluster, $partitionCount ) {
		$config = new HashSearchConfig( [
			'CirrusSearchElasticaWritePartitionCounts' => $partitionCount,
		] );
		$settings = new ClusterSettings( $config, $cluster );
		$this->assertEquals( $expected, $settings->getElasticaWritePartitionCount() );
	}
}
