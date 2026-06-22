<?php

namespace CirrusSearch\Query;

use CirrusSearch\CirrusConfigNames;
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
			CirrusConfigNames::EnableRegex => false,
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
			CirrusConfigNames::EnableRegex => true,
			CirrusConfigNames::WikimediaExtraPlugin => [],
		], [ HashSearchConfig::FLAG_INHERIT ] );
		$this->assertWarnings(
			new InSourceFeature( $config ),
			[ [ 'cirrussearch-feature-not-available', 'insource regex' ] ],
			'insource:/abc/i'
		);
	}
}
