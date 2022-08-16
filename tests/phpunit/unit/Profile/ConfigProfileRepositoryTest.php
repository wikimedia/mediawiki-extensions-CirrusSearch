<?php

namespace CirrusSearch\Profile;

use CirrusSearch\CirrusTestCase;
use ExtensionRegistry;

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
		$scope = ExtensionRegistry::getInstance()->setAttributeForTest( 'profiles', [] );
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
		$scope = ExtensionRegistry::getInstance()->setAttributeForTest( 'profiles', [] );
		$repo = new ConfigProfileRepository( 'my_type', 'my_name',  'profiles', $config );
		$this->assertFalse( $repo->hasProfile( 'prof3' ) );
		$this->assertNull( $repo->getProfile( 'prof3' ) );
	}

	public function testBadConfigWithHas() {
		$config = new \HashConfig( [ 'profiles' => 123 ] );
		$scope = ExtensionRegistry::getInstance()->setAttributeForTest( 'profiles', [] );
		$repo = new ConfigProfileRepository( 'my_type', 'my_name',  'profiles', $config );
		$this->expectException( SearchProfileException::class );
		$repo->hasProfile( 'prof3' );
	}

	public function testBadConfigWithGet() {
		$config = new \HashConfig( [ 'profiles' => 123 ] );
		$scope = ExtensionRegistry::getInstance()->setAttributeForTest( 'profiles', [] );
		$repo = new ConfigProfileRepository( 'my_type', 'my_name',  'profiles', $config );
		$this->expectException( SearchProfileException::class );
		$repo->getProfile( 'prof3' );
	}

	public function testAttribute() {
		$config = new \HashConfig( [] );
		$scope = ExtensionRegistry::getInstance()->setAttributeForTest( 'profiles', [ 'prof1' => [ 'foo' ] ] );
		$repo = new ConfigProfileRepository( 'my_type', 'my_name',  'profiles', $config );
		$this->assertTrue( $repo->hasProfile( 'prof1' ) );
		$this->assertFalse( $repo->hasProfile( 'prof2' ) );
		$this->assertEquals( [ 'foo' ], $repo->getProfile( 'prof1' ) );
	}

	public function testConfigAndAttribute() {
		$config = new \HashConfig( [ 'profiles' => [
			'prof1' => [ '1c' ],
			'prof3' => [ '3c' ],
		] ] );
		$scope = ExtensionRegistry::getInstance()->setAttributeForTest( 'profiles', [
			'prof2' => [ '2a' ],
			'prof3' => [ '3a' ],
		] );
		$repo = new ConfigProfileRepository( 'my_type', 'my_name',  'profiles', $config );
		$this->assertTrue( $repo->hasProfile( 'prof1' ) );
		$this->assertTrue( $repo->hasProfile( 'prof2' ) );
		$this->assertTrue( $repo->hasProfile( 'prof3' ) );
		$this->assertEquals( [ '1c' ], $repo->getProfile( 'prof1' ) );
		$this->assertEquals( [ '2a' ], $repo->getProfile( 'prof2' ) );
		// existing configuration cannot be overridden by other extensions
		$this->assertEquals( [ '3c' ], $repo->getProfile( 'prof3' ) );
	}
}
