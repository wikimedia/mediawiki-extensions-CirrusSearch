<?php

namespace CirrusSearch\Query;

use CirrusSearch\Search\SearchContext;

class PreferRecentFeatureText extends \MediaWikiTestCase {

	public function parseProvider() {
		return array(
			'uses defaults if nothing provided' => array(
				'',
				.6,
				160,
				'prefer-recent:'
			),
			'doesnt absorb unrelated pieces' => array(
				'other',
				.6,
				160,
				'prefer-recent: other',
			),
			'can specify only decay portion' => array(
				'',
				.9,
				160,
				'prefer-recent:.9',
			),
			'can specify decay and half life' => array(
				'',
				.01,
				123,
				'prefer-recent:.01,123',
			),
		);
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

		$feature = new PreferRecentFeature( new \HashConfig( array(
			'CirrusSearchPreferRecentDefaultHalfLife' => 160,
			'CirrusSearchPreferRecentUnspecifiedDecayPortion' => .6,
		) ) );
		$remaining = $feature->apply( $context, $term );
		$this->assertEquals( $expectedRemaining, $remaining );

	}
}
