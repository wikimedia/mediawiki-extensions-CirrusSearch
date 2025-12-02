<?php

namespace CirrusSearch\Test\Integration\Hooks;

use CirrusSearch\CirrusIntegrationTestCase;
use CirrusSearch\Hooks\CirrusSearchApiQuerySiteInfoGeneralInfoHook;
use MediaWiki\MediaWikiServices;

/**
 * Using Database here because, in part, what we are testing is that this is a
 * valid way to query max page id.
 *
 * @group Database
 * @covers \CirrusSearch\Hooks\CirrusSearchApiQuerySiteInfoGeneralInfoHook
 */
class CirrusSearchApiQuerySiteInfoGeneralInfoHookTest extends CirrusIntegrationTestCase {
	public function testHappyPath() {
		$dbProvider = MediaWikiServices::getInstance()->getConnectionProvider();
		$hook = new CirrusSearchApiQuerySiteInfoGeneralInfoHook( $dbProvider );
		$result = [];
		$hook->onAPIQuerySiteInfoGeneralInfo( null, $result );
		// It's an empty db, so no pages exist. But returning a number is good.
		$this->assertCount( 1, $result, 1 );
		$this->assertArrayHasKey( 'max-page-id', $result );
		$this->assertIsInt( $result['max-page-id'] );
		$this->assertGreaterThanOrEqual( 0, $result['max-page-id'] );
	}
}
