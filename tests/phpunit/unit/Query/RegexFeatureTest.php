<?php

namespace CirrusSearch\Query;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\HashSearchConfig;

/**
 * @group CirrusSearch
 * @covers \CirrusSearch\Query\BaseRegexFeature
 */
class RegexFeatureTest extends CirrusTestCase {
	use SimpleKeywordFeatureTestTrait;

	public function testGivesWarningIfNotEnabled() {
		$config = new HashSearchConfig( [
			'CirrusSearchEnableRegex' => false,
		], [ HashSearchConfig::FLAG_INHERIT ] );
		$this->assertWarnings(
			new InSourceFeature( $config ),
			[ [ 'cirrussearch-feature-not-available', 'insource regex' ] ],
			'insource:/abc/i'
		);
	}

	public function testGivesWarningIfPluginNotAvailable() {
		// Regex requires the wikimedia-extra plugin; without it the feature is unavailable.
		$config = new HashSearchConfig( [
			'CirrusSearchEnableRegex' => true,
			'CirrusSearchWikimediaExtraPlugin' => [],
		], [ HashSearchConfig::FLAG_INHERIT ] );
		$this->assertWarnings(
			new InSourceFeature( $config ),
			[ [ 'cirrussearch-feature-not-available', 'insource regex' ] ],
			'insource:/abc/i'
		);
	}
}
