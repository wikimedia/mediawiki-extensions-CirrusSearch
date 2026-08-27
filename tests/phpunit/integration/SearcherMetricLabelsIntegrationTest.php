<?php

namespace CirrusSearch;

use CirrusSearch\Query\HasRecommendationFeature;
use CirrusSearch\Search\SearchQueryBuilder;
use CirrusSearch\Test\DummyConnection;

/**
 * Wiring of the metric dimensions emitted with request_time_seconds, see T434975.
 *
 * @covers \CirrusSearch\Searcher::getMetricLabels
 * @group CirrusSearch
 */
class SearcherMetricLabelsIntegrationTest extends CirrusIntegrationTestCase {

	private function newSearcher( CirrusSearchHookRunner $hookRunner ): Searcher {
		// Inherit the real configuration, the query builders need a lot of it.
		$searchConfig = $this->newHashSearchConfig(
			[ 'CirrusSearchIndexBaseName' => 'wiki' ],
			[ HashSearchConfig::FLAG_INHERIT ]
		);
		return new class( new DummyConnection( $searchConfig ), 0, 20, $searchConfig, [ NS_MAIN ],
			null, false, null, null, null, null, $hookRunner ) extends Searcher {
			public function metricLabels(): array {
				return $this->getMetricLabels();
			}
		};
	}

	private function newHookRunner(): CirrusSearchHookRunner {
		return $this->createCirrusSearchHookRunner( [
			'CirrusSearchAddQueryFeatures' => static function ( $config, &$extraFeatures ) {
				$extraFeatures[] = new HasRecommendationFeature( 1000 );
			},
		] );
	}

	public function testLabelsBeforeAnySearch() {
		$searcher = $this->newSearcher( $this->newHookRunner() );
		// A searcher that has not been handed a query still reports both labels, and the
		// default pool, because stats drops samples with an inconsistent set of label keys.
		$this->assertSame(
			[ 'pool' => 'Search', 'weighted_tags' => 'no' ],
			$searcher->metricLabels()
		);
	}

	public static function provideQueries() {
		return [
			'plain text' => [ 'foo bar', 'no' ],
			'weighted tags keyword' => [ 'hasrecommendation:image', 'yes' ],
			'weighted tags keyword with text' => [ 'foo hasrecommendation:image', 'yes' ],
			'negated weighted tags keyword' => [ '-hasrecommendation:link', 'yes' ],
		];
	}

	/**
	 * @dataProvider provideQueries
	 */
	public function testWeightedTagsLabelReflectsTheQuery( string $term, string $expected ) {
		$hookRunner = $this->newHookRunner();
		$searcher = $this->newSearcher( $hookRunner );
		$query = SearchQueryBuilder::newFTSearchQueryBuilder(
				$searcher->getSearchContext()->getConfig(), $term,
				$this->namespacePrefixParser(), $hookRunner
			)
			->setDebugOptions( CirrusDebugOptions::forDumpingQueriesInUnitTests() )
			->build();
		$searcher->search( $query );

		$labels = $searcher->metricLabels();
		$this->assertSame( $expected, $labels['weighted_tags'] );
		$this->assertSame( 'Search', $labels['pool'] );
	}
}
