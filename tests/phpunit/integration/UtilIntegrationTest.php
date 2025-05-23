<?php

namespace CirrusSearch;

use CirrusSearch\Profile\SearchProfileServiceFactoryFactory;
use MediaWiki\Config\Config;
use MediaWiki\Language\Language;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\TestingAccessWrapper;

/**
 * @covers \CirrusSearch\Util
 */
class UtilIntegrationTest extends CirrusIntegrationTestCase {
	private function getWanCache(): WANObjectCache {
		return $this->getServiceContainer()->getMainWANObjectCache();
	}

	/**
	 * Put data for a wiki into test cache.
	 * @param WANObjectCache $cache
	 * @param string $wiki
	 */
	private function putDataIntoCache( WANObjectCache $cache, $wiki ) {
		$key = $cache->makeGlobalKey( 'cirrussearch-boost-templates', $wiki );
		$cache->set( $key, [ "Data for $wiki" => 2 ] );
	}

	/**
	 * @covers \CirrusSearch\Util::getDefaultBoostTemplates
	 */
	public function testgetDefaultBoostTemplates() {
		$cache = $this->getWanCache();
		$this->putDataIntoCache( $cache, 'ruwiki' );
		$this->putDataIntoCache( $cache, 'cywiki' );

		$cy = Util::getDefaultBoostTemplates( $this->getHashConfig( 'cywiki' ) );
		$ru = Util::getDefaultBoostTemplates( $this->getHashConfig( 'ruwiki' ) );

		$this->assertNotEquals( $cy, $ru, 'Boosts should change with language' );

		// no cache means empty array
		$this->assertArrayEquals( [],
			Util::getDefaultBoostTemplates( $this->getHashConfig( 'hywiki' ) ) );
	}

	/**
	 * @covers \CirrusSearch\Util::getDefaultBoostTemplates
	 */
	public function testOverrideBoostTemplatesWithOnWikiConfig() {
		$configValues = [
			'CirrusSearchBoostTemplates' => [
				'Featured' => 2,
			],
		];
		$config = $this->getHashConfig( 'ruwiki', $configValues );

		// On wiki config should override config templates
		$cache = $this->getWanCache();
		$this->putDataIntoCache( $cache, 'ruwiki' );
		$ru = Util::getDefaultBoostTemplates( $config );
		$this->assertNotEquals( $configValues['CirrusSearchBoostTemplates'], $ru );
	}

	/**
	 * @covers \CirrusSearch\Util::getDefaultBoostTemplates
	 */
	public function testOverrideBoostTemplatesWithOnCurrentWikiConfig() {
		$configValues = [
			'CirrusSearchBoostTemplates' => [
				'Featured' => 2,
			],
		];
		$config = $this->getHashConfig( WikiMap::getCurrentWikiId(), $configValues );

		// On wiki config should override config templates
		$cache = $this->getWanCache();
		$this->putDataIntoCache( $cache, WikiMap::getCurrentWikiId() );

		$ru = Util::getDefaultBoostTemplates( $config );
		$this->assertNotEquals( $configValues['CirrusSearchBoostTemplates'], $ru );
	}

	/**
	 * @covers \CirrusSearch\Util::getDefaultBoostTemplates
	 */
	public function testDisableOverrideBoostTemplatesWithOnWikiConfig() {
		$configValues = [
			'CirrusSearchBoostTemplates' => [
				'Featured' => 3,
			],
			// we can disable on wiki customization
			'CirrusSearchIgnoreOnWikiBoostTemplates' => true,
		];
		$config = $this->getHashConfig( 'ruwiki', $configValues );

		$cache = $this->getWanCache();
		$this->putDataIntoCache( $cache, 'ruwiki' );

		$ru = Util::getDefaultBoostTemplates( $config );
		$this->assertArrayEquals( $configValues['CirrusSearchBoostTemplates'], $ru );
	}

	public function testgetDefaultBoostTemplatesLocal() {
		$services = MediaWikiServices::getInstance();
		$services->resetServiceForTesting( 'MessageCache' );
		$services->redefineService(
			'MessageCache',
			function () {
				return $this->getMockCache();
			}
		);
		TestingAccessWrapper::newFromClass( Util::class )->defaultBoostTemplates = null;

		$cache = $this->getWanCache();
		$config = $this->getHashConfig( WikiMap::getCurrentWikiId() );
		$key = $cache->makeGlobalKey( 'cirrussearch-boost-templates', $config->getWikiId() );

		// FIXME: we cannot really test the default value for $config
		// with Util::getDefaultBoostTemplates(). It looks like
		// MediaWikiServices initializes the current wiki SearchConfig
		// when WikiMap::getCurrentWikiId() == 'wiki' and then it's
		// cached, the test framework seems to update the wiki name to
		// wiki-unittest_ making it impossible to test if we are running
		// on the local wiki.
		// resetting MediaWikiServices would be nice but it does not
		// seem to be trivial.
		$cur = Util::getDefaultBoostTemplates( $config );
		reset( $cur );
		$this->assertStringContainsString(
			' in ' . $services->getContentLanguageCode()->toString(), key( $cur )
		);

		// Check we cached it
		$cached = $cache->get( $key );
		$this->assertNotEmpty( $cached, 'Should cache the value' );
	}

	/**
	 * Produces mock message cache for injecting messages
	 * @return \MessageCache
	 */
	private function getMockCache() {
		$mock = $this->createMock( \MessageCache::class );
		$mock->method( 'get' )->willReturnCallback( static function ( $key, $useDB, $lang ) {
			$langCode = $lang instanceof Language ? $lang->getCode() : $lang;
			return "This is $key in $langCode|100%";
		} );
		return $mock;
	}

	protected function tearDown(): void {
		TestingAccessWrapper::newFromClass( Util::class )->defaultBoostTemplates = null;
		parent::tearDown();
	}

	/**
	 * @dataProvider provideTestIdentifyNamespace
	 * @param string $namespace
	 * @param int|bool $expected
	 * @param string $method
	 */
	public function testIdentifyNamespace( $namespace, $expected, $method ) {
		$this->overrideConfigValues( [
			MainConfigNames::ExtraNamespaces => [
				100 => 'Maçon',
				101 => 'Cédille',
				102 => 'Groß',
				103 => 'Norræn goðafræði',
				104 => 'لَحَم', // لحم
				105 => 'Thảo_luận',
			],
			MainConfigNames::NamespaceAliases => [
				'Mañsoner' => 100,
			],
		] );
		$language = $this->getServiceContainer()->getContentLanguage();
		$this->assertEquals( $expected, Util::identifyNamespace( $namespace, $method, $language ) );
	}

	public static function provideTestIdentifyNamespace() {
		return [
			'simple' => [ 'macon', 100, 'naive' ],
			'simple utr30' => [ 'macon', 100, 'utr30' ],
			'both sides' => [ 'mäcon', 100, 'naive' ],
			'both sides utr30' => [ 'mäcon', 100, 'utr30' ],
			'simple alias' => [ 'mansoner', 100, 'naive' ],
			'simple alias utr30' => [ 'mansoner', 100, 'utr30' ],
			'no match' => [ 'maçons', false, 'naive' ],
			'no match utr30' => [ 'maçons', false, 'utr30' ],
			'arabic' => [ 'لحم', 104, 'naive' ],
			'arabic utr30' => [ 'لحم', 104, 'utr30' ],
			'gods are not naive' => [ 'norræn godafræði', false, 'naive' ],
			'gods are weak with utr30' => [ 'norraen godafraeði', 103, 'utr30' ],
			'case folding can be gross' => [ 'gross', 102, 'naive' ],
			'case folding can be gross even with utr30' => [ 'gross', 102, 'utr30' ]
		];
	}

	/**
	 * Create test hash config for a wiki.
	 * @param string $wiki
	 * @param mixed[] $moreData additional config
	 * @return HashSearchConfig
	 */
	private function getHashConfig( $wiki, array $moreData = [] ) {
		if ( !isset( $moreData['CirrusSearchBoostTemplates'] ) ) {
			$moreData['CirrusSearchBoostTemplates'] = [];
		}
		if ( !isset( $moreData['CirrusSearchIgnoreOnWikiBoostTemplates'] ) ) {
			$moreData['CirrusSearchIgnoreOnWikiBoostTemplates'] = false;
		}
		$moreData[ '_wikiID' ] = $wiki;
		return $this->newHashSearchConfig( $moreData );
	}

	/**
	 * @param array $config
	 * @param array $flags
	 * @param Config|null $inherited
	 * @param SearchProfileServiceFactoryFactory|null $factoryFactory
	 * @return SearchConfig
	 */
	public function newHashSearchConfig(
		array $config = [],
		$flags = [],
		?Config $inherited = null,
		?SearchProfileServiceFactoryFactory $factoryFactory = null
	): SearchConfig {
		return new HashSearchConfig(
			$config,
			$flags,
			$inherited,
			$factoryFactory ?: $this->hostWikiSearchProfileServiceFactory(
				null,
				$this->getServiceContainer()->getUserOptionsLookup()
			)
		);
	}
}
