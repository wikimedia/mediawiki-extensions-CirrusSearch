<?php

namespace CirrusSearch;

use MediaWiki\Logger\LoggerFactory;

class CirrusIntegrationTestCase extends \MediaWikiIntegrationTestCase {
	use CirrusTestCaseTrait;
	use CirrusIntegrationTestCaseTrait;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		LoggerFactory::getInstance( 'CirrusSearchIntegTest' )->debug( 'Using seed ' . self::getSeed() );
	}
}
