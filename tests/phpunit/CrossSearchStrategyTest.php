<?php

namespace CirrusSearch;

/**
 * @covers \CirrusSearch\CrossSearchStrategy
 * @group CirrusSearch
 */
class CrossSearchStrategyTest extends CirrusTestCase {

	public function testHostWikiOnly() {
		$strategy = CrossSearchStrategy::hostWikiOnlyStrategy();

		$this->assertFalse( $strategy->isCrossLanguageSearchSupported() );
		$this->assertFalse( $strategy->isCrossLanguageSearchSupported() );
		$this->assertFalse( $strategy->isExtraIndicesSearchSupported() );

		$this->assertSame( $strategy, CrossSearchStrategy::hostWikiOnlyStrategy() );
	}

	public function testAllWikis() {
		$strategy = CrossSearchStrategy::allWikisStrategy();

		$this->assertTrue( $strategy->isCrossLanguageSearchSupported() );
		$this->assertTrue( $strategy->isCrossLanguageSearchSupported() );
		$this->assertTrue( $strategy->isExtraIndicesSearchSupported() );

		$this->assertSame( $strategy, CrossSearchStrategy::allWikisStrategy() );
	}
}
