<?php

namespace CirrusSearch\Query;

use CirrusSearch\HashSearchConfig;

/**
 * @group CirrusSearch
 * @covers \CirrusSearch\Query\RegexFeature
 */
class RegexFeatureText extends BaseSimpleKeywordFeatureTest {

	public function testGivesWarningIfNotEnabled() {
		$config = new HashSearchConfig( [
			'CirrusSearchEnableRegex' => false,
		], [ 'inherit' ] );
		$this->assertWarnings(
			new RegexFeature( $config, 'source', 'source_text' ),
			[ [ 'cirrussearch-feature-not-available', 'insource regex' ] ],
			'insource:/abc/'
		);
	}
}
