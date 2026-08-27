<?php

namespace CirrusSearch;

/**
 * @covers \CirrusSearch\Searcher::weightedTagsLabel
 * @covers \CirrusSearch\Util::poolCounterLabel
 * @group CirrusSearch
 */
class SearcherMetricLabelsTest extends CirrusTestCase {

	public static function provideWeightedTagsLabel() {
		return [
			'no keyword at all' => [ [], 'no' ],
			'unrelated keyword' => [ [ 'intitle' ], 'no' ],
			'hasrecommendation' => [ [ 'hasrecommendation' ], 'yes' ],
			'articletopic' => [ [ 'articletopic' ], 'yes' ],
			'drafttopic' => [ [ 'drafttopic' ], 'yes' ],
			'articlecountry' => [ [ 'articlecountry' ], 'yes' ],
			'custommatch, from WikibaseMediaInfo' => [ [ 'custommatch' ], 'yes' ],
			'combined with an unrelated keyword' => [ [ 'intitle', 'hasrecommendation' ], 'yes' ],
			'several weighted tags keywords' => [ [ 'hasrecommendation', 'articletopic' ], 'yes' ],
		];
	}

	/**
	 * @dataProvider provideWeightedTagsLabel
	 */
	public function testWeightedTagsLabel( array $featuresUsed, string $expected ) {
		$this->assertSame( $expected, Searcher::weightedTagsLabel( $featuresUsed ) );
	}

	public static function providePoolCounterLabel() {
		return [
			[ PoolCounterKey::SEARCH, 'Search' ],
			[ PoolCounterKey::EXPENSIVE_FULL_TEXT, 'ExpensiveFullText' ],
			[ PoolCounterKey::PREFIX, 'Prefix' ],
			[ PoolCounterKey::MORE_LIKE, 'MoreLike' ],
			[ PoolCounterKey::SEMANTIC, 'Semantic' ],
			[ PoolCounterKey::AUTOMATED, 'Automated' ],
			[ PoolCounterKey::COMPLETION, 'Completion' ],
			'no prefix to strip' => [ 'Search', 'Search' ],
		];
	}

	/**
	 * @dataProvider providePoolCounterLabel
	 */
	public function testPoolCounterLabel( string $poolCounterType, string $expected ) {
		$this->assertSame( $expected, Util::poolCounterLabel( $poolCounterType ) );
	}
}
