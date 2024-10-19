<?php

namespace CirrusSearch\Profile;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\HashSearchConfig;
use MediaWiki\Registration\ExtensionRegistry;

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
		$configArray = [
			'CirrusSearchCompletionSuggesterSubphrases' => [
				'use' => false,
			],
			'profiles' => $profiles,
		];
		$config = new HashSearchConfig( $configArray );
		$repo = CompletionSearchProfileRepository::fromConfig( 'my_type', 'my_repo', 'profiles', $config );
		$this->assertEquals( 'my_type', $repo->repositoryType() );
		$this->assertEquals( 'my_repo', $repo->repositoryName() );
		$this->assertTrue( $repo->hasProfile( 'normal' ) );
		$this->assertFalse( $repo->hasProfile( 'normal-subphrases' ) );
		$this->assertNotNull( $repo->getProfile( 'normal' ) );
		$this->assertNull( $repo->getProfile( 'normal-subphrases' ) );
		$this->assertArrayEquals( [
			'normal' => $profiles['normal'],
		], $repo->listExposedProfiles(), false, true );

		$configArray = [
			'CirrusSearchCompletionSuggesterSubphrases' => [
				'use' => true,
			],
			'profiles' => $profiles,
		];

		// Without subphrases the normal-subphrases is visible
		$config = new HashSearchConfig( $configArray );
		$fromConfig = CompletionSearchProfileRepository::fromConfig( 'my_type', 'my_repo', 'profiles', $config );
		$fromRepo = CompletionSearchProfileRepository::fromRepo( new ConfigProfileRepository( 'my_type', 'my_repo',
			'profiles', $config ), $config );

		foreach ( [ $fromConfig, $fromRepo ] as $repo ) {
			$this->assertEquals( 'my_type', $repo->repositoryType() );
			$this->assertEquals( 'my_repo', $repo->repositoryName() );
			$this->assertTrue( $repo->hasProfile( 'normal' ) );
			$this->assertTrue( $repo->hasProfile( 'normal-subphrases' ) );
			$this->assertNotNull( $repo->getProfile( 'normal' ) );
			$this->assertNotNull( $repo->getProfile( 'normal-subphrases' ) );
			$this->assertFalse( $repo->hasProfile( 'custom' ) );
			$this->assertArrayEquals( $profiles, $repo->listExposedProfiles(), false, true );
		}
	}

	public function testFromFile() {
		$configArray = [
			'CirrusSearchCompletionSuggesterSubphrases' => [
				'use' => false,
			],
		];

		// Without subphrases the normal-subphrases is visible
		$config = new HashSearchConfig( $configArray );
		$registry = new ExtensionRegistry();
		$scope = $registry->setAttributeForTest( 'profiles', [] );
		$repo = CompletionSearchProfileRepository::fromFile( 'my_type', 'my_repo',
			__DIR__ . '/../../../../profiles/SuggestProfiles.config.php', $config );
		$this->assertEquals( 'my_type', $repo->repositoryType() );
		$this->assertEquals( 'my_repo', $repo->repositoryName() );
		$this->assertTrue( $repo->hasProfile( 'fuzzy' ) );
	}
}
