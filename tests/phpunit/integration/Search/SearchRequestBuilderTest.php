<?php

namespace CirrusSearch\Search;

use CirrusSearch\CirrusDebugOptions;
use CirrusSearch\CirrusIntegrationTestCase;
use CirrusSearch\Connection;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\SearchConfig;

/**
 * @covers \CirrusSearch\Search\SearchRequestBuilder
 */
class SearchRequestBuilderTest extends CirrusIntegrationTestCase {
	private function searchRequestBuilder(
		array $allOverride = [],
		array $otherOverride = [],
		?array $namespaces = null,
		$indexBaseName = 'trebuchet'
	): SearchRequestBuilder {
		$defaults = [
			'CirrusSearchDefaultCluster' => 'dc1',
			'CirrusSearchReplicaGroup' => 'a',
			'CirrusSearchClusters' => [
				'a' => [ 'replica' => 'dc1', 'group' => 'a', '10.1.2.3:9200' ],
				'b' => [ 'replica' => 'dc1', 'group' => 'b', '10.3.2.1:9201' ],
			],
		];
		$hostOverrides = array_merge( $defaults, $allOverride );
		// Host config is accessed via \GlobalVarConfig, so we need to apply these globally.
		$this->overrideConfigValues( $hostOverrides );
		$otherWikiConfig = new HashSearchConfig( $otherOverride + $hostOverrides );

		$context = new SearchContext( $otherWikiConfig, $namespaces, CirrusDebugOptions::forDumpingQueriesInUnitTests() );
		$conn = new Connection( new SearchConfig() );
		return new SearchRequestBuilder( $context, $conn, $indexBaseName );
	}

	public function testCanOverridePageType() {
		$builder = $this->searchRequestBuilder();
		$index = $this->createNoOpMock( \Elastica\Index::class );
		$builder->setIndex( $index );
		$this->assertSame( $index, $builder->getIndex() );
	}

	public function testGetPageTypeWithCrossClusterSearch() {
		// Disabled: no prefix
		$builder = $this->searchRequestBuilder( [
			'CirrusSearchCrossClusterSearch' => false,
		] );
		$this->assertEquals( 'trebuchet', $builder->getIndex()->getName() );

		// Cross cluster assigned to same replica group: no prefix
		$builder = $this->searchRequestBuilder( [
			'CirrusSearchCrossClusterSearch' => true,
		] );
		$this->assertEquals( 'trebuchet', $builder->getIndex()->getName() );

		// Cross cluster assigned to different replica group: apply prefix
		$builder = $this->searchRequestBuilder( [
			'CirrusSearchCrossClusterSearch' => true,
		], [
			'CirrusSearchReplicaGroup' => 'b',
		] );
		$this->assertEquals( 'b:trebuchet', $builder->getIndex()->getName() );
	}

	public static function provideNamespaceMapProvider(): \Generator {
		yield "search content" => [
			[ '0' => 'index_content', '100' => 'index_content' ], [ 0, 100 ], 'a', 'index', 'index_content'
		];
		yield "search all" => [
			[ '0' => 'index_content', '100' => 'index_general' ], [ 0, 100 ], 'a', 'index', 'index'
		];

		yield "mismatch" => [
			[ '1' => 'index_content', '101' => 'index_general' ], [ 0, 1, 2 ], 'a', 'index', 'index'
		];
		yield "search content cross-cluster" => [
			[ '0' => 'index_content', '100' => 'index_content' ], [ 0, 100 ], 'b', 'index', 'b:index_content'
		];
		yield "search all cross-cluster" => [
			[ '0' => 'index_content', '100' => 'index_general' ], [ 0, 100 ], 'b', 'index', 'b:index'
		];

		yield "mismatch cross-cluster" => [
			[ '1' => 'index_content', '101' => 'index_general' ], [ 0, 1, 2 ], 'b', 'index', 'b:index'
		];
	}

	/**
	 * @dataProvider provideNamespaceMapProvider
	 */
	public function testUsingConcreteNamespaceMap(
		$concreteNamespaceMap,
		$searchNamespace,
		$targetGroup,
		$indexBaseName,
		$expectedIndexName
	) {
		$builder = $this->searchRequestBuilder(
			[ 'CirrusSearchCrossClusterSearch' => true ],
			[
				'CirrusSearchReplicaGroup' => $targetGroup,
				'CirrusSearchConcreteNamespaceMap' => $concreteNamespaceMap,
			],
			$searchNamespace,
			$indexBaseName
		);
		$this->assertEquals( $expectedIndexName, $builder->getIndex()->getName() );
	}
}
