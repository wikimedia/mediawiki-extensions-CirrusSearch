<?php

namespace CirrusSearch\Maintenance;

use MediaWikiIntegrationTestCase;
use Wikimedia\TestingAccessWrapper;

/**
 * @group CirrusSearch
 */
class RunSearchTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->setMwGlobals( 'wgCirrusSearchWMFExtraFeatures', [
			'weighted_tags' => [ 'build' => false, 'use' => false ]
		] );
	}

	/**
	 * @covers \CirrusSearch\Maintenance\RunSearch::changeGlobalKeyPath
	 */
	public function testChangeGlobalKeyPath() {
		global $wgCirrusSearchWMFExtraFeatures;
		$this->assertFalse( $wgCirrusSearchWMFExtraFeatures['weighted_tags']['build'] );

		/** @var RunSearch $runner */
		$runner = TestingAccessWrapper::newFromObject( new RunSearch() );
		$runner->changeGlobalKeyPath(
			'wgCirrusSearchWMFExtraFeatures.weighted_tags.build', true,
			[ 'wgCirrusSearchWMFExtraFeatures' => true ]
		);

		$this->assertTrue( $wgCirrusSearchWMFExtraFeatures['weighted_tags']['build'] );
	}

}
