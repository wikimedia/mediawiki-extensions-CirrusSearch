<?php

namespace CirrusSearch\Query;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\CrossSearchStrategy;

/**
 * @covers \CirrusSearch\Query\LanguageFeature
 * @group CirrusSearch
 */
class LanguageFeatureTest extends CirrusTestCase {
	use SimpleKeywordFeatureTestTrait;

	public static function provideQueries() {
		$tooMany = array_map(
			static function ( $l ) {
				return (string)$l;
			},
			range( 1, LanguageFeature::QUERY_LIMIT + 20 )
		);
		$actualLangs = array_slice( $tooMany, 0, LanguageFeature::QUERY_LIMIT );
		return [
			'simple' => [
				'inlanguage:fr',
				[ 'langs' => [ 'fr' ] ],
				[ 'multi_match' => [ 'fields' => [ 'language' ], 'query' => 'fr' ] ],
				[]
			],
			'multiple' => [
				'inlanguage:fr|en',
				[ 'langs' => [ 'fr', 'en' ] ],
				[ 'bool' => [
					'minimum_should_match' => 1,
					'should' => [
						[ 'multi_match' => [ 'fields' => [ 'language' ], 'query' => 'fr' ] ],
						[ 'multi_match' => [ 'fields' => [ 'language' ], 'query' => 'en' ] ],
					] ] ],
				[]
			],
			'multiple with comma back compat' => [
				'inlanguage:fr,en',
				[ 'langs' => [ 'fr', 'en' ] ],
				[ 'bool' => [
					'minimum_should_match' => 1,
					'should' => [
						[ 'multi_match' => [ 'fields' => [ 'language' ], 'query' => 'fr' ] ],
						[ 'multi_match' => [ 'fields' => [ 'language' ], 'query' => 'en' ] ],
					] ] ],
				[ [ 'cirrussearch-inlanguage-deprecate-comma' ] ]
			],
			'too many' => [
				'inlanguage:' . implode( '|', $tooMany ),
				[ 'langs' => $actualLangs ],
				[ 'bool' => [
					'minimum_should_match' => 1,
					'should' => array_map(
						static function ( $l ) {
							return [ 'multi_match' => [ 'fields' => [ 'language' ], 'query' => (string)$l ] ];
						},
						range( 1, LanguageFeature::QUERY_LIMIT )
					) ] ],
				[ [ 'cirrussearch-feature-too-many-conditions', 'inlanguage', LanguageFeature::QUERY_LIMIT ] ]
			],
		];
	}

	/**
	 * @dataProvider provideQueries
	 */
	public function testTooManyLanguagesWarning( $term, $expected, array $filter, $warnings ) {
		$feature = new LanguageFeature( $this->newHashSearchConfig() );
		$this->assertParsedValue( $feature, $term, $expected, $warnings );
		$this->assertCrossSearchStrategy( $feature, $term, CrossSearchStrategy::allWikisStrategy() );
		$this->assertExpandedData( $feature, $term, [], [] );
		$this->assertWarnings( $feature, $warnings, $term );
		$this->assertFilter( $feature, $term, $filter, $warnings );
	}

	public function testExtraFields() {
		$feature = new LanguageFeature( $this->newHashSearchConfig( [
			"CirrusSearchLanguageKeywordExtraFields" => [ "lang.field.1", "lang.field.2" ]
		] ) );
		$expectedFilter = [ 'multi_match' => [ 'fields' => [ 'language', "lang.field.1", "lang.field.2" ], 'query' => "somelang" ] ];
		$this->assertFilter( $feature, 'inlanguage:somelang', $expectedFilter, [] );
	}
}
