<?php

namespace CirrusSearch\Profile;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\HashSearchConfig;
use ExtensionRegistry;
use Wikimedia\ScopedCallback;

/**
 * @group CirrusSearch
 * @covers \CirrusSearch\Profile\CompletionSearchProfileRepository
 */
class CompletionSearchProfileRepositoryTest extends CirrusTestCase {

	public function testFromConfig() {
		// Without subphrases the normal-subphrases is hidden
		$profiles = [
			'normal' => [
				'fst' => [
					'plain-normal' => [
						'field' => 'suggest',
					],
					'plain-stop-normal' => [
						'field' => 'suggest-stop'
					],
				],
			],
			'normal-subphrases' => [
				'fst' => [
					'plain-normal' => [
						'field' => 'suggest',
					],
					'plain-stop-normal' => [
						'field' => 'suggest-stop',
					],
					'plain-subphrase' => [
						'field' => 'suggest-subphrases',
					],
				],
			],
		];
		$profilesFromAttributes = [
			'custom' => [
				'fst' => [
					'plain-normal' => [
						'field' => 'suggest',
					],
				],
			],
		];
		$configArray = [
			'CirrusSearchCompletionSuggesterSubphrases' => [
				'use' => false,
			],
			'profiles' => $profiles,
		];
		$config = new HashSearchConfig( $configArray );
		$scope = ExtensionRegistry::getInstance()->setAttributeForTest( 'profiles', $profilesFromAttributes );
		$repo = CompletionSearchProfileRepository::fromConfig( 'my_type', 'my_repo', 'profiles', $config );
		$this->assertEquals( 'my_type', $repo->repositoryType() );
		$this->assertEquals( 'my_repo', $repo->repositoryName() );
		$this->assertTrue( $repo->hasProfile( 'normal' ) );
		$this->assertFalse( $repo->hasProfile( 'normal-subphrases' ) );
		$this->assertTrue( $repo->hasProfile( 'custom' ) );
		$this->assertNotNull( $repo->getProfile( 'normal' ) );
		$this->assertNull( $repo->getProfile( 'normal-subphrases' ) );
		$this->assertNotNull( $repo->getProfile( 'custom' ) );
		$this->assertArrayEquals( [
			'normal' => $profiles['normal'],
			'custom' => $profilesFromAttributes['custom'],
		], $repo->listExposedProfiles(), false, true );

		ScopedCallback::consume( $scope );
		$scope = ExtensionRegistry::getInstance()->setAttributeForTest( 'profiles', [] );
		$configArray = [
			'CirrusSearchCompletionSuggesterSubphrases' => [
				'use' => true,
			],
			'profiles' => $profiles,
		];

		// Without subphrases the normal-subphrases is visible
		$config = new HashSearchConfig( $configArray );
		$repo = CompletionSearchProfileRepository::fromConfig( 'my_type', 'my_repo', 'profiles', $config );
		$this->assertEquals( 'my_type', $repo->repositoryType() );
		$this->assertEquals( 'my_repo', $repo->repositoryName() );
		$this->assertTrue( $repo->hasProfile( 'normal' ) );
		$this->assertTrue( $repo->hasProfile( 'normal-subphrases' ) );
		$this->assertNotNull( $repo->getProfile( 'normal' ) );
		$this->assertNotNull( $repo->getProfile( 'normal-subphrases' ) );
		$this->assertFalse( $repo->hasProfile( 'custom' ) );
		$this->assertArrayEquals( $profiles, $repo->listExposedProfiles(), false, true );
	}

	public function testFromFile() {
		$configArray = [
			'CirrusSearchCompletionSuggesterSubphrases' => [
				'use' => false,
			],
		];

		// Without subphrases the normal-subphrases is visible
		$config = new HashSearchConfig( $configArray );
		$scope = ExtensionRegistry::getInstance()->setAttributeForTest( 'profiles', [] );
		$repo = CompletionSearchProfileRepository::fromFile( 'my_type', 'my_repo',
			__DIR__ . '/../../../../profiles/SuggestProfiles.config.php', $config );
		$this->assertEquals( 'my_type', $repo->repositoryType() );
		$this->assertEquals( 'my_repo', $repo->repositoryName() );
		$this->assertTrue( $repo->hasProfile( 'fuzzy' ) );
	}
}
