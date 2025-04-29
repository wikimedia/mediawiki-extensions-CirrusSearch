<?php

namespace CirrusSearch\Query;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\CrossSearchStrategy;

/**
 * @covers \CirrusSearch\Query\HasRecommendationFeature
 * @group CirrusSearch
 */
class HasRecommendationFeatureTest extends CirrusTestCase {
	use SimpleKeywordFeatureTestTrait;

	public static function provideQueries() {
		$tooMany = array_map(
			static function ( $l ) {
				return (string)$l;
			},
			range( 1, HasRecommendationFeature::QUERY_LIMIT + 5 )
		);
		$actualrecFlags = array_slice( $tooMany, 0, HasRecommendationFeature::QUERY_LIMIT );
		return [
			'simple' => [
				'hasrecommendation:image',
				[ 'recommendationflags' => [ 'image' ] ],
				[ 'match' => [ 'weighted_tags' => [ 'query' => 'recommendation.image/exists' ] ] ],
				[]
			],
			'multiple' => [
				'hasrecommendation:link|image',
				[ 'recommendationflags' => [ 'link', 'image' ] ],
				[ 'bool' => [
					'minimum_should_match' => 1,
					'should' => [
						[ 'match' => [ 'weighted_tags' => [ 'query' => 'recommendation.link/exists' ] ] ],
						[ 'match' => [ 'weighted_tags' => [ 'query' => 'recommendation.image/exists' ] ] ],
					] ] ],
				[]
			],
			'too many' => [
				'hasrecommendation:' . implode( '|', $tooMany ),
				[ 'recommendationflags' => $actualrecFlags ],
				[ 'bool' => [
					'minimum_should_match' => 1,
					'should' => array_merge( ...array_map(
						static function ( $l ) {
							return [
								[ 'match' => [ 'weighted_tags' => [ 'query' => "recommendation." . $l . '/exists' ] ] ],
							];
						},
						range( 1, HasRecommendationFeature::QUERY_LIMIT )
					) ) ] ],
				[ [ 'cirrussearch-feature-too-many-conditions', 'hasrecommendation',
					HasRecommendationFeature::QUERY_LIMIT ] ]
			],
		];
	}

	/**
	 * @dataProvider provideQueries
	 * @param string $term
	 * @param array $expected
	 * @param array $filter
	 * @param array $warnings
	 */
	public function testApply( $term, $expected, array $filter, $warnings ) {
		$feature = new HasRecommendationFeature();
		$this->assertParsedValue( $feature, $term, $expected, $warnings );
		$this->assertCrossSearchStrategy( $feature, $term, CrossSearchStrategy::hostWikiOnlyStrategy() );
		$this->assertExpandedData( $feature, $term, [], [] );
		$this->assertWarnings( $feature, $warnings, $term );
		$this->assertFilter( $feature, $term, $filter, $warnings );
	}
}
