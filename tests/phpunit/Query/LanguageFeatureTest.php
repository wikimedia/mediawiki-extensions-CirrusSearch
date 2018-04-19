<?php

namespace CirrusSearch\Query;

use CirrusSearch\CrossSearchStrategy;

/**
 * @covers \CirrusSearch\Query\LanguageFeature
 * @group CirrusSearch
 */
class LanguageFeatureTest extends BaseSimpleKeywordFeatureTest {

	public function testTooManyLanguagesWarning() {
		$this->assertWarnings(
			new LanguageFeature(),
			[ [ 'cirrussearch-feature-too-many-conditions', 'inlanguage', LanguageFeature::QUERY_LIMIT ] ],
			'inlanguage:' . implode( ',', range( 1, 40 ) )
		);
	}

	public function testCrossSearchStrategy() {
		$feature = new LanguageFeature();
		$this->assertCrossSearchStrategy( $feature, "inlanguage:fr,en", CrossSearchStrategy::allWikisStrategy() );
	}
}
