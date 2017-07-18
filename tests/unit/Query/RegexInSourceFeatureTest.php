<?php

namespace CirrusSearch\Query;

use CirrusSearch\HashSearchConfig;

/**
 * @group CirrusSearch
 */
class RegexInSourceFeatureText extends BaseSimpleKeywordFeatureTest {

	public function testGivesWarningIfNotEnabled() {
		$config = new HashSearchConfig( [
			'CirrusSearchEnableRegex' => false,
		], [ 'inherit' ] );
		$this->assertWarnings(
			new RegexInSourceFeature( $config ),
			[ [ 'cirrussearch-feature-not-available', 'insource regex' ] ],
			'insource:/abc/'
		);
	}
}
