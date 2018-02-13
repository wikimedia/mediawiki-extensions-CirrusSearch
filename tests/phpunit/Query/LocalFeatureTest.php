<?php


namespace CirrusSearch\Query;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\Search\SearchContext;

/**
 * @covers \CirrusSearch\Query\LocalFeature
 * @covers \CirrusSearch\Query\SimpleKeywordFeature
 * @group CirrusSearch
 */
class LocalFeatureTest extends CirrusTestCase {
	public function parseProvider() {
		return [
			'simple local' => [
				'foo bar',
				true,
				'local:foo bar'
			],
			'simple local with sep spaces' => [
				' foo bar',
				true,
				'local: foo bar'
			],
			'local can have spaces before' => [
				'foo bar',
				true,
				'  local:foo bar'
			],
			'local must be at the beginning' => [
				'foo local:bar',
				false,
				'foo local:bar',
			],
		];
	}

	/**
	 * @dataProvider parseProvider
	 */
	public function testParse( $expectedRemaining, $isLocal, $term ) {
		$context = $this->getMockBuilder( SearchContext::class )
			->disableOriginalConstructor()
			->getMock();
		if ( $isLocal ) {
			$context->expects( $this->once() )
				->method( 'setLimitSearchToLocalWiki' )
				->with( true );
		} else {
			$context->expects( $this->never() )
				->method( 'setLimitSearchToLocalWiki' );
		}
		$feature = new LocalFeature( new \HashConfig( [] ) );
		$remaining = $feature->apply( $context, $term );
		$this->assertEquals( $expectedRemaining, $remaining );
	}
}
