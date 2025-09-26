<?php

namespace CirrusSearch\Query;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\CrossSearchStrategy;
use MediaWiki\Message\Message;

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
		$actualrecFlags = array_map(
			static fn ( string $r ): array => [ 'flag' => $r, 'comp' => null, 'threshold' => null ],
			array_slice( $tooMany, 0, HasRecommendationFeature::QUERY_LIMIT )
		);
		return [
			'simple' => [
				'hasrecommendation:image',
				[ 'recommendationflags' => [ [ 'flag' => 'image', 'comp' => null, 'threshold' => null ] ] ],
				[ 'match' => [ 'weighted_tags' => [ 'query' => 'recommendation.image/exists' ] ] ],
				[]
			],
			'multiple' => [
				'hasrecommendation:link|image',
				[ 'recommendationflags' => [
					[ 'flag' => 'link', 'comp' => null, 'threshold' => null ],
					[ 'flag' => 'image', 'comp' => null, 'threshold' => null ]
				] ],
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
			'with threshold' => [
				'hasrecommendation:link>0.3|image<0.8|tone<=0.4|typo>=0.3|peacock=1.0',
				[ 'recommendationflags' => [
					[ 'flag' => 'link', 'comp' => '>', 'threshold' => 0.3 ],
					[ 'flag' => 'image', 'comp' => '<', 'threshold' => 0.8 ],
					[ 'flag' => 'tone', 'comp' => '<=', 'threshold' => 0.4 ],
					[ 'flag' => 'typo', 'comp' => '>=', 'threshold' => 0.3 ],
					[ 'flag' => 'peacock', 'comp' => '=', 'threshold' => 1.0 ]
				] ],
				[ 'bool' => [
					'minimum_should_match' => 1,
					'should' => [
						[ 'term_freq' => [ 'field' => 'weighted_tags', 'term' => 'recommendation.link/exists', 'gt' => 300 ] ],
						[ 'term_freq' => [ 'field' => 'weighted_tags', 'term' => 'recommendation.image/exists', 'lt' => 800 ] ],
						[ 'term_freq' => [ 'field' => 'weighted_tags', 'term' => 'recommendation.tone/exists', 'lte' => 400 ] ],
						[ 'term_freq' => [ 'field' => 'weighted_tags', 'term' => 'recommendation.typo/exists', 'gte' => 300 ] ],
						[ 'term_freq' => [ 'field' => 'weighted_tags', 'term' => 'recommendation.peacock/exists', 'eq' => 1000 ] ],
					] ] ],
				[]
			],
			'with bad thresholds' => [
				'hasrecommendation:link>1.3|image<foo|tone=',
				[ 'recommendationflags' => [
					[ 'flag' => 'link', 'comp' => null, 'threshold' => null ],
					[ 'flag' => 'image', 'comp' => null, 'threshold' => null ],
					[ 'flag' => 'tone', 'comp' => null, 'threshold' => null ],
				] ],
				[ 'bool' => [
					'minimum_should_match' => 1,
					'should' => [
						[ 'match' => [ 'weighted_tags' => [ 'query' => 'recommendation.link/exists' ] ] ],
						[ 'match' => [ 'weighted_tags' => [ 'query' => 'recommendation.image/exists' ] ] ],
						[ 'match' => [ 'weighted_tags' => [ 'query' => 'recommendation.tone/exists' ] ] ],
					] ] ],
				[
					[ 'cirrussearch-invalid-keyword-threshold', Message::plaintextParam( '1.3' ) ],
					[ 'cirrussearch-invalid-keyword-threshold', Message::plaintextParam( 'foo' ) ],
					[ 'cirrussearch-invalid-keyword-threshold', Message::plaintextParam( '' ) ],
				]
			]
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
		$feature = new HasRecommendationFeature( 1000 );
		$this->assertParsedValue( $feature, $term, $expected, $warnings );
		$this->assertCrossSearchStrategy( $feature, $term, CrossSearchStrategy::hostWikiOnlyStrategy() );
		$this->assertExpandedData( $feature, $term, [], [] );
		$this->assertWarnings( $feature, $warnings, $term );
		$this->assertFilter( $feature, $term, $filter, $warnings );
	}
}
