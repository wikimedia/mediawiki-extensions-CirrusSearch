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
		$this->overrideConfigValue( 'CirrusSearchWeightedTags', [
			'build' => false,
			'use' => false
		] );
	}

	/**
	 * @covers \CirrusSearch\Maintenance\RunSearch::changeGlobalKeyPath
	 */
	public function testChangeGlobalKeyPath() {
		global $wgCirrusSearchWeightedTags;
		$this->assertFalse( $wgCirrusSearchWeightedTags['build'] );

		/** @var RunSearch $runner */
		$runner = TestingAccessWrapper::newFromObject( new RunSearch() );
		$runner->changeGlobalKeyPath(
			'wgCirrusSearchWeightedTags.build', true,
			[ 'wgCirrusSearchWeightedTags' => true ]
		);

		$this->assertTrue( $wgCirrusSearchWeightedTags['build'] );
	}

}
