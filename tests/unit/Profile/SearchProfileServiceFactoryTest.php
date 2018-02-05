<?php


namespace CirrusSearch\Profile;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\HashSearchConfig;

/**
 * @group CirrusSearch
 * @covers \CirrusSearch\Profile\SearchProfileServiceFactory
 */
class SearchProfileServiceFactoryTest extends CirrusTestCase {

	/**
	 * @dataProvider provideTypeAndContext
	 * @param string $type
	 * @param string $context
	 * @throws \Exception
	 * @throws \FatalError
	 * @throws \MWException
	 */
	public function testSaneDefaults( $type, $context ) {
		// Even with an empty search config we should have default profiles
		// available
		$factory = new SearchProfileServiceFactory();
		$service = $factory->loadService( new HashSearchConfig( [] ) );
		$this->assertNotNull( $service->getProfileName( $type, $context ) );
		$this->assertNotNull( $service->loadProfile( $type, $context ) );
	}

	public static function provideTypeAndContext() {
		return [
			'rescore fulltext' => [ SearchProfileService::RESCORE, SearchProfileService::CONTEXT_DEFAULT ],
			'rescore prefix' => [ SearchProfileService::RESCORE, SearchProfileService::CONTEXT_PREFIXSEARCH ],
			'similarity prefix' => [ SearchProfileService::SIMILARITY, SearchProfileService::CONTEXT_DEFAULT ],
			'crossproject block order' => [ SearchProfileService::CROSS_PROJECT_BLOCK_SCORER, SearchProfileService::CONTEXT_DEFAULT ],
			'completion' => [ SearchProfileService::COMPLETION, SearchProfileService::CONTEXT_DEFAULT ],
		];
	}

	/**
	 * @dataProvider provideOverrides
	 * @param string $type
	 * @param string $context
	 * @param string $overrideType
	 * @param string $overrideKey
	 * @param array[] $profiles
	 * @param bool $forceMwGlobals
	 * @throws \Exception
	 * @throws \FatalError
	 * @throws \MWException
	 */
	public function testOverrides( $type, $context, $overrideType, $overrideKey, $profiles ) {
		$factory = new SearchProfileServiceFactory();
		$this::setTemporaryHook( 'CirrusSearchProfileService',
			function ( SearchProfileService $service ) use ( $type, $profiles ) {
				$service->registerArrayRepository( $type, 'unit_test', $profiles );
			}
		);

		$profileName = key( $profiles );

		// Don't use TestUser it may have been polluted with default config from other tests.
		$user = $this->getMockBuilder( \User::class )
			->getMock();
		$user->expects( $this->any() )
			->method( 'getOption' )
			->will( $this->returnValue( null ) );

		if ( $overrideType === 'uri' ) {
			$request = new \FauxRequest( [ $overrideKey => $profileName ] );
			$config = new HashSearchConfig( [] );
		} elseif ( $overrideType === 'pref' ) {
			$request = new \FauxRequest();
			$user = $this->getTestUser( [ 'cirrus-profiles', $type, $context, $overrideKey ] )->getUser();
			$user->setOption( $overrideKey, $profileName );
			$config = new HashSearchConfig( [] );
		} elseif ( $overrideType === 'config' ) {
			$request = new \FauxRequest();
			$config = new HashSearchConfig( [ $overrideKey => $profileName ] );
		} else {
			throw new \RuntimeException( "Unknown override type $overrideType" );
		}
		$service = $factory->loadService( $config, $request, $user, true );
		$this->assertEquals( key( $profiles ), $service->getProfileName( $type, $context ) );
		$this->assertEquals( reset( $profiles ), $service->loadProfile( $type, $context ) );
	}

	public static function provideOverrides() {
		return [
			'rescore fulltext by uri' => [
				SearchProfileService::RESCORE, SearchProfileService::CONTEXT_DEFAULT,
				'uri', 'cirrusRescoreProfile', [ 'unittest' => [] ]
			],
			'rescore fulltext by config' => [
				SearchProfileService::RESCORE, SearchProfileService::CONTEXT_DEFAULT,
				'config', 'CirrusSearchRescoreProfile', [ 'unittest' => [] ]
			],
			'rescore prefix by uri' => [
				SearchProfileService::RESCORE, SearchProfileService::CONTEXT_PREFIXSEARCH,
				'uri', 'cirrusRescoreProfile', [ 'unittest' => [] ]
			],
			'rescore prefix by config' => [
				SearchProfileService::RESCORE, SearchProfileService::CONTEXT_PREFIXSEARCH,
				'config', 'CirrusSearchPrefixSearchRescoreProfile', [ 'unittest' => [] ]
			],
			'similarity by config' => [
				SearchProfileService::SIMILARITY, SearchProfileService::CONTEXT_DEFAULT,
				'config', 'CirrusSearchSimilarityProfile', [ 'unittest' => [] ]
			],
			'crossproject block scorer by config' => [
				SearchProfileService::CROSS_PROJECT_BLOCK_SCORER, SearchProfileService::CONTEXT_DEFAULT,
				'config', 'CirrusSearchCrossProjectOrder', [ 'unittest' => [] ]
			],
			'completion by user pref' => [
				SearchProfileService::COMPLETION, SearchProfileService::CONTEXT_DEFAULT,
				'pref', 'cirrussearch-pref-completion-profile', [ 'unittest' => [] ]
			],
			'completion by config' => [
				SearchProfileService::COMPLETION, SearchProfileService::CONTEXT_DEFAULT,
				'config', 'CirrusSearchCompletionSettings', [ 'unittest' => [] ],
			],
		];
	}
}
