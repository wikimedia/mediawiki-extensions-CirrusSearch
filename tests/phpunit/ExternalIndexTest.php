<?php

namespace CirrusSearch;

/**
 * @covers CirrusSearch\ExternalIndex
 */
class ExternalIndexTest extends \PHPUnit\Framework\TestCase {

	public function testSimpleConfiguration() {
		$config = new HashSearchConfig( [] );
		$idx = new ExternalIndex( $config, 'foo' );
		$this->assertEquals( 'foo', $idx->getSearchIndex( 'anything' ) );

		$config = new HashSearchConfig( [
			'CirrusSearchExtraIndexClusters' => [
				'foo' => [
					'a2' => 'a1',
				],
			],
		] );
		$idx = new ExternalIndex( $config, 'foo' );
		$this->assertEquals( 'foo', $idx->getSearchIndex( 'anything' ) );
		$this->assertEquals( 'foo', $idx->getSearchIndex( 'a1' ) );
		$this->assertEquals( 'a1:foo', $idx->getSearchIndex( 'a2' ) );
	}

	public function getWriteClustersProvider() {
		$tests = [];

		// Current wiki writes to cluster `1` in datacenters `a` and `b`
		$config = [
			'CirrusSearchWriteClusters' => [ 'a1', 'b1' ],
			'CirrusSearchExtraIndexClusters' => [
				// Direct all external index usage to cluster `2`
				'unittest' => [
					'a1' => 'a2',
					'b1' => 'b2',
				]
			],
		];
		$assertions = [
			// Writes for all clusters should return cluster 2
			[ 'source' => 'a1', 'target' => 'a2' ],
			[ 'source' => 'b1', 'target' => 'b2' ],
			// Unconfigured clusters should return themselves
			[ 'source' => 'a2', 'target' => 'a2' ],
			[ 'source' => 'b2', 'target' => 'b2' ],
			// We dont know if they exist
			[ 'source' => 'c', 'target' => 'c' ],
		];

		foreach ( $assertions as $testCase ) {
			$tests[] = [ $config, $testCase['source'], $testCase['target'] ];
		}

		// Wiki writing to cluster `2` should continue sending external
		// writes to same clusters.
		$config['CirrusSearchWriteClusters'] = [ 'a2', 'b2' ];
		foreach ( $assertions as $testCase ) {
			$tests[] = [ $config, $testCase['source'], $testCase['target'] ];
		}

		return $tests;
	}

	/**
	 * @dataProvider getWriteClustersProvider
	 */
	public function testGetWriteClusters( $config, $sourceCluster, $targetCluster ) {
		$config = new HashSearchConfig( $config + [
			'CirrusSearchClusters' => [
				'a1' => [],
				'a2' => [],
				'b1' => [],
				'b2' => [],
			],
		] );
		$idx = new ExternalIndex( $config, 'unittest' );
		$this->assertEquals( $targetCluster, $idx->getWriteCluster( $sourceCluster ) );
	}

	public function testGetSearchIndex() {
		$config = new HashSearchConfig( [
			'CirrusSearchExtraIndexClusters' => [
				'unittest' => [
					'a2' => 'a1',
					'b2' => 'b1',
				],
			],
		] );
		$index = new ExternalIndex( $config, 'unittest' );
		$this->assertEquals( 'unittest', $index->getSearchIndex( 'a1' ) );
		$this->assertEquals( 'a1:unittest', $index->getSearchIndex( 'a2' ) );
		$this->assertEquals( 'unittest', $index->getSearchIndex( 'b1' ) );
		$this->assertEquals( 'b1:unittest', $index->getSearchIndex( 'b2' ) );
	}

	public function getBoostsProvider() {
		return [
			'unconfigured' => [ '', [], [] ],
			'configured for different index' => [ '', [], [
				'notme' => [ 'wiki' => 'otherwiki', 'boosts' => [ 'Zomg' => 0.44 ] ],
			] ],
			'configured for this index' => [ 'otherwiki', [ 'Zomg' => 0.44 ], [
				'testindex' => [ 'wiki' => 'otherwiki', 'boosts' => [ 'Zomg' => 0.44 ] ],
			] ],
		];
	}

	/**
	 * @dataProvider getBoostsProvider
	 */
	public function testGetBoosts( $expectedWiki, $expectedBoosts, $boostConfig ) {
		$config = new HashSearchConfig( [
			'CirrusSearchExtraIndexBoostTemplates' => $boostConfig,
		] );
		$idx = new ExternalIndex( $config, 'testindex', [] );
		list( $wiki, $boosts ) = $idx->getBoosts();
		$this->assertEquals( $expectedWiki, $wiki );
		$this->assertEquals( $expectedBoosts, $boosts );
	}
}
