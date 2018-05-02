<?php

namespace CirrusSearch\Query;

use CirrusSearch\HashSearchConfig;
use CirrusSearch\Search\SearchContext;

/**
 * @covers \CirrusSearch\Query\PreferRecentFeature
 * @covers \CirrusSearch\Query\SimpleKeywordFeature
 * @group CirrusSearch
 */
class PreferRecentFeatureTest extends BaseSimpleKeywordFeatureTest {

	public function parseProvider() {
		return [
			'uses defaults if nothing provided' => [
				'',
				null,
				null,
				'prefer-recent:'
			],
			'doesnt absorb unrelated pieces' => [
				'other',
				null,
				null,
				'prefer-recent: other',
			],
			'doesnt absorb unrelated pieces even if collapsed' => [
				// trailing space is arbitrarily added by SimpleKeywordFeature
				'other ',
				null,
				null,
				'prefer-recent:other',
			],
			'can specify only decay portion' => [
				'',
				0.9,
				null,
				'prefer-recent:.9',
			],
			'can specify decay and half life' => [
				'',
				0.01,
				123,
				'prefer-recent:.01,123',
			],
		];
	}

	/**
	 * @dataProvider parseProvider
	 */
	public function testParse( $expectedRemaining, $expectedDecay, $expectedHalfLife, $term ) {
		$defaultHalfLife = 160;
		$defaultDecay = 0.6;

		$config = new HashSearchConfig( [
			'CirrusSearchPreferRecentDefaultHalfLife' => $defaultHalfLife,
			'CirrusSearchPreferRecentUnspecifiedDecayPortion' => $defaultDecay,
		] );
		$feature = new PreferRecentFeature( $config );
		$this->assertRemaining( $feature, $term, $expectedRemaining );
		$expectedParsedValue = [];
		if ( $expectedDecay !== null ) {
			$expectedParsedValue['decay'] = $expectedDecay;
		}
		if ( $expectedHalfLife !== null ) {
			$expectedParsedValue['halfLife'] = $expectedHalfLife;
		}
		$this->assertParsedValue( $feature, $term, $expectedParsedValue === [] ? null : $expectedParsedValue, [] );
		$context = new SearchContext( $config );
		$feature->apply( $context, $term );
		if ( $expectedDecay !== null ) {
			$this->assertEquals( $context->getPreferRecentDecayPortion(), $expectedDecay );
		}
		if ( $expectedHalfLife !== null ) {
			$this->assertEquals( $context->getPreferRecentHalfLife(), $expectedHalfLife );
		}
	}
}
