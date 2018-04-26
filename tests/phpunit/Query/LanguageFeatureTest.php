<?php

namespace CirrusSearch\Query;

use CirrusSearch\CrossSearchStrategy;

/**
 * @covers \CirrusSearch\Query\LanguageFeature
 * @group CirrusSearch
 */
class LanguageFeatureTest extends BaseSimpleKeywordFeatureTest {

	public function provideQueries() {
		$tooMany = array_map(
			function ( $l ) {
				return (string)$l;
			},
			range( 1, LanguageFeature::QUERY_LIMIT + 20 )
		);
		$actualLangs = array_slice( $tooMany, 0, LanguageFeature::QUERY_LIMIT );
		return [
			'simple' => [
				'inlanguage:fr',
				[ 'langs' => [ 'fr' ] ],
				[]
			],
			'multiple' => [
				'inlanguage:fr,en',
				[ 'langs' => [ 'fr', 'en' ] ],
				[]
			],
			'too many' => [
				'inlanguage:' . implode( ',', $tooMany ),
				[ 'langs' => $actualLangs ],
				[ [ 'cirrussearch-feature-too-many-conditions', 'inlanguage', LanguageFeature::QUERY_LIMIT ] ]
			],
		];
	}

	/**
	 * @dataProvider provideQueries()
	 * @param string $term
	 * @param array $expected
	 * @param array $warnings
	 */
	public function testTooManyLanguagesWarning( $term, $expected, $warnings ) {
		$feature = new LanguageFeature();
		$this->assertParsedValue( $feature, $term, $expected, $warnings );
		$this->assertCrossSearchStrategy( $feature, $term, CrossSearchStrategy::allWikisStrategy() );
		$this->assertExpandedData( $feature, $term, [], [] );
		$this->assertWarnings( $feature, $warnings, $term );
	}
}
