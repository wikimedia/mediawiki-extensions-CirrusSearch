<?php

namespace CirrusSearch\Profile;

use CirrusSearch\CirrusTestCase;
use MediaWiki\Registration\ExtensionRegistry;

/**
 * @covers \CirrusSearch\Profile\ExtensionRegistryProfileRepository
 */
class ExtensionRegistryProfileRepositoryTest extends CirrusTestCase {

	public function test() {
		$registry = new ExtensionRegistry();
		$profiles = [
			'prof1' => [],
			'prof2' => [],
		];
		$scope = $registry->setAttributeForTest( 'my_attribute', $profiles );
		$repo = new ExtensionRegistryProfileRepository( 'my_type', 'my_name', 'my_attribute', $registry );
		$this->assertEquals( 'my_type', $repo->repositoryType() );
		$this->assertEquals( 'my_name', $repo->repositoryName() );
		$this->assertTrue( $repo->hasProfile( 'prof1' ) );
		$this->assertTrue( $repo->hasProfile( 'prof2' ) );
		$this->assertFalse( $repo->hasProfile( 'prof3' ) );
		$this->assertSame( $repo->getProfile( 'prof1' ), $profiles['prof1'] );
		$this->assertSame( $repo->listExposedProfiles(), $profiles );
	}
}
