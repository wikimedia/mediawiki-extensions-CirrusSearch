<?php

namespace CirrusSearch\Test\Integration\Profile;

use CirrusSearch\CirrusIntegrationTestCase;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\InterwikiResolverFactory;
use CirrusSearch\Profile\SearchProfileService;
use CirrusSearch\Profile\SearchProfileServiceFactory;
use MediaWiki\Interwiki\NullInterwikiLookup;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\User\Options\StaticUserOptionsLookup;
use Wikimedia\ObjectCache\EmptyBagOStuff;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * @group CirrusSearch
 * @covers \CirrusSearch\Profile\SearchProfileServiceFactory
 */
class SearchProfileServiceFactoryTest extends CirrusIntegrationTestCase {
	/**
	 * @dataProvider provideExposedProfileType
	 */
	public function testExportedProfilesWithI18N( $type, array $must_have ) {
		$factory = $this->getFactory( [], null, [] );
		$service = $factory->loadService( new HashSearchConfig( [] ) );
		$profiles = $service->listExposedProfiles( $type );

		$seen = [];
		foreach ( $profiles as $name => $profile ) {
			$this->assertArrayHasKey( 'i18n_msg', $profile, "Profile $name in $type has i18n_msg key" );
			$this->assertTrue( wfMessage( $profile['i18n_msg'] )->exists(),
				"Profile $name in $type has i18n message set" );
			$seen[] = $name;
		}
		$missing = array_diff( $must_have, $seen );
		$this->assertSame( [], $missing, "Profiles of type $type must include all must_have profiles" );
	}

	public static function provideExposedProfileType() {
		return [
			'rescore' => [
				SearchProfileService::RESCORE,
				[ 'classic', 'empty', 'classic_noboostlinks', 'wsum_inclinks',
					'wsum_inclinks_pv', 'popular_inclinks_pv', 'popular_inclinks' ]
			],
			'completion' => [
				SearchProfileService::COMPLETION,
				[ 'classic', 'fuzzy', 'normal', 'strict' ]
			]
		];
	}

	private function getFactory() {
		$config = new HashSearchConfig( [] );
		$httpClient = new \NullMultiHttpClient( [] );
		$interWikiLookup = new NullInterwikiLookup();
		$registry = new ExtensionRegistry();
		$resolver = InterwikiResolverFactory::build( $config, WANObjectCache::newEmpty(),
			$interWikiLookup, $registry, $httpClient );

		return new SearchProfileServiceFactory( $resolver, $config, new EmptyBagOStuff(),
			$this->createCirrusSearchHookRunner(), new StaticUserOptionsLookup( [] ), $registry
		);
	}
}
