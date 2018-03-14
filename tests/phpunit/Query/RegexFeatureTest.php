<?php

namespace CirrusSearch\Query;

use CirrusSearch\HashSearchConfig;

/**
 * @group CirrusSearch
 * @covers \CirrusSearch\Query\BaseRegexFeature
 */
class RegexFeatureTest extends BaseSimpleKeywordFeatureTest {

	public function testGivesWarningIfNotEnabled() {
		$config = new HashSearchConfig( [
			'CirrusSearchEnableRegex' => false,
		], [ 'inherit' ] );
		$this->assertWarnings(
			new InSourceFeature( $config ),
			[ [ 'cirrussearch-feature-not-available', 'insource regex' ] ],
			'insource:/abc/i'
		);
	}
}
