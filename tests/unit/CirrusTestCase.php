<?php

namespace CirrusSearch;

use MediaWikiTestCase;
use MediaWiki\MediaWikiServices;

/**
 * Base class for Cirrus test cases
 * @group CirrusSearch
 */
abstract class CirrusTestCase extends MediaWikiTestCase {
	protected function setUp() {
		parent::setUp();
		MediaWikiServices::getInstance()
			->resetServiceForTesting( InterwikiResolver::SERVICE );
	}
}
