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
	private function searchRequestBuilder( array $allOverride = [], array $otherOverride = [] ): SearchRequestBuilder {
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

		$context = new SearchContext( $otherWikiConfig, null, CirrusDebugOptions::forDumpingQueriesInUnitTests() );
		$conn = new Connection( new SearchConfig() );
		$indexBaseName = 'trebuchet';
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
}
