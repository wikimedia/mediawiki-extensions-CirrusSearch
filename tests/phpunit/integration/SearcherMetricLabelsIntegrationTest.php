<?php

namespace CirrusSearch;

use CirrusSearch\Query\HasRecommendationFeature;
use CirrusSearch\Search\SearchQueryBuilder;
use CirrusSearch\Test\DummyConnection;

/**
 * Wiring of the metric dimensions emitted with request_time_seconds, see T434975.
 *
 * @covers \CirrusSearch\Searcher::getMetricLabels
 * @covers \CirrusSearch\InterwikiSearcher::getMetricLabels
 * @group CirrusSearch
 */
class SearcherMetricLabelsIntegrationTest extends CirrusIntegrationTestCase {

	private function newLocalConfig( array $settings = [] ): SearchConfig {
		// Inherit the real configuration, the query builders need a lot of it.
		return $this->newHashSearchConfig(
			$settings + [ 'CirrusSearchIndexBaseName' => 'wiki' ],
			[ HashSearchConfig::FLAG_INHERIT ]
		);
	}

	private function newOtherWikiConfig(): SearchConfig {
		// _wikiID marks the config as the config of another wiki, see HashSearchConfig::isLocalWiki().
		return $this->newHashSearchConfig(
			[ '_wikiID' => 'otherwiki' ],
			[ HashSearchConfig::FLAG_INHERIT ]
		);
	}

	private function newSearcher( CirrusSearchHookRunner $hookRunner, ?SearchConfig $searchConfig = null ): Searcher {
		$searchConfig ??= $this->newLocalConfig();
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
		$this->assertSame(
			[ 'weighted_tags' => 'no', 'interwiki' => 'no' ],
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
		$this->assertSame( 'no', $labels['interwiki'] );
	}

	public function testCrossLanguageSearchLabel() {
		$hookRunner = $this->newHookRunner();
		$query = SearchQueryBuilder::newFTSearchQueryBuilder(
				$this->newLocalConfig( [ CirrusConfigNames::EnableAltLanguage => true ] ), 'foo bar',
				$this->namespacePrefixParser(), $hookRunner
			)
			->setDebugOptions( CirrusDebugOptions::forDumpingQueriesInUnitTests() )
			->build();
		// Do the same steps as LangDetectFallbackMethod::rewrite() and Searcher::makeSearcher().
		$crossLangQuery = SearchQueryBuilder::forCrossLanguageSearch( $this->newOtherWikiConfig(), $query )->build();
		$searcher = $this->newSearcher( $hookRunner, $crossLangQuery->getSearchConfig() );
		$searcher->search( $crossLangQuery );

		$this->assertSame( 'crosslanguage', $searcher->metricLabels()['interwiki'] );
	}

	public static function provideInterwikiSearcherConfig() {
		return [
			'local wiki config' => [ false ],
			// InterwikiSearcher::getInterwikiResults() replaces the config with the config of each sister wiki.
			'sister wiki config' => [ true ],
		];
	}

	/**
	 * @dataProvider provideInterwikiSearcherConfig
	 */
	public function testInterwikiSearcherLabels( bool $sisterWikiConfig ) {
		$config = $sisterWikiConfig ? $this->newOtherWikiConfig() : $this->newLocalConfig();
		$searcher = new class( new DummyConnection( $this->newLocalConfig() ), $config, [ NS_MAIN ],
			null, null, null, null, null, $this->newHookRunner() ) extends InterwikiSearcher {
			public function metricLabels(): array {
				return $this->getMetricLabels();
			}
		};
		$this->assertSame(
			[ 'interwiki' => 'crossproject', 'weighted_tags' => 'no' ],
			$searcher->metricLabels()
		);
	}
}
