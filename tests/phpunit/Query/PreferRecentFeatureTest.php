<?php

namespace CirrusSearch\Query;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\Search\SearchContext;

/**
 * @covers \CirrusSearch\Query\PreferRecentFeature
 * @covers \CirrusSearch\Query\SimpleKeywordFeature
 * @group CirrusSearch
 */
class PreferRecentFeatureTest extends CirrusTestCase {

	public function parseProvider() {
		return [
			'uses defaults if nothing provided' => [
				'',
				0.6,
				160,
				'prefer-recent:'
			],
			'doesnt absorb unrelated pieces' => [
				'other',
				0.6,
				160,
				'prefer-recent: other',
			],
			'doesnt absorb unrelated pieces even if collapsed' => [
				// trailing space is arbitrarily added by SimpleKeywordFeature
				'other ',
				0.6,
				160,
				'prefer-recent:other',
			],
			'can specify only decay portion' => [
				'',
				0.9,
				160,
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
		$context = $this->getMockBuilder( SearchContext::class )
			->disableOriginalConstructor()
			->getMock();
		$context->expects( $this->once() )
			->method( 'setPreferRecentOptions' )
			->with( $expectedDecay, $expectedHalfLife );

		$feature = new PreferRecentFeature( new \HashConfig( [
			'CirrusSearchPreferRecentDefaultHalfLife' => 160,
			'CirrusSearchPreferRecentUnspecifiedDecayPortion' => 0.6,
		] ) );
		$remaining = $feature->apply( $context, $term );
		$this->assertEquals( $expectedRemaining, $remaining );
	}
}
