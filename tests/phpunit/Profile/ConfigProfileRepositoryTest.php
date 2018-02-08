<?php

namespace CirrusSearch\Profile;

use CirrusSearch\CirrusTestCase;

/**
 * @group CirrusSearch
 * @covers \CirrusSearch\Profile\ConfigProfileRepository
 */
class ConfigProfileRepositoryTest extends CirrusTestCase {

	/**
	 * @throws \ConfigException
	 */
	public function test() {
		$config = new \HashConfig( [
			'profiles' => [
				'prof1' => [],
				'prof2' => [],
			]
		] );
		$repo = new ConfigProfileRepository( 'my_type', 'my_name',  'profiles', $config );
		$this->assertEquals( 'my_type', $repo->repositoryType() );
		$this->assertEquals( 'my_name', $repo->repositoryName() );
		$this->assertTrue( $repo->hasProfile( 'prof1' ) );
		$this->assertFalse( $repo->hasProfile( 'prof3' ) );
		$this->assertArrayEquals( $config->get( 'profiles' ), $repo->listExposedProfiles() );
		$this->assertEquals( [], $repo->getProfile( 'prof1' ) );
		$this->assertNull( $repo->getProfile( 'prof3' ) );
	}

	public function testNoConfig() {
		$config = new \HashConfig( [] );
		$repo = new ConfigProfileRepository( 'my_type', 'my_name',  'profiles', $config );
		$this->assertFalse( $repo->hasProfile( 'prof3' ) );
		$this->assertNull( $repo->getProfile( 'prof3' ) );
	}

	/**
	 * @expectedException \CirrusSearch\Profile\SearchProfileException
	 */
	public function testBadConfigWithHas() {
		$config = new \HashConfig( [ 'profiles' => 123 ] );
		$repo = new ConfigProfileRepository( 'my_type', 'my_name',  'profiles', $config );
		$repo->hasProfile( 'prof3' );
	}

	/**
	 * @expectedException \CirrusSearch\Profile\SearchProfileException
	 */
	public function testBadConfigWithGet() {
		$config = new \HashConfig( [ 'profiles' => 123 ] );
		$repo = new ConfigProfileRepository( 'my_type', 'my_name',  'profiles', $config );
		$repo->getProfile( 'prof3' );
	}
}
