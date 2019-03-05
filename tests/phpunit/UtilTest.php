<?php

namespace CirrusSearch;

use MediaWiki\MediaWikiServices;
use Language;

/**
 * Test Util functions.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @group CirrusSearch
 * @covers \CirrusSearch\Util
 */
class UtilTest extends CirrusTestCase {

	/**
	 * @dataProvider recursiveSameTestCases
	 */
	public function testRecursiveSame( $same, $lhs, $rhs ) {
		$this->assertEquals( $same, Util::recursiveSame( $lhs, $rhs ) );
	}

	public static function recursiveSameTestCases() {
		return [
			[ true, [], [] ],
			[ false, [ true ], [] ],
			[ false, [ true ], [ false ] ],
			[ true, [ true ], [ true ] ],
			[ false, [ 1 ], [ 2 ] ],
			[ false, [ 1, 2 ], [ 2, 1 ] ],
			[ true, [ 1, 2, 3 ], [ 1, 2, 3 ] ],
			[ false, [ [ 1 ] ], [ [ 2 ] ] ],
			[ true, [ [ 1 ] ], [ [ 1 ] ] ],
			[ true, [ 'candle' => [ 'wax' => 'foo' ] ], [ 'candle' => [ 'wax' => 'foo' ] ] ],
			[ false, [ 'candle' => [ 'wax' => 'foo' ] ], [ 'candle' => [ 'wax' => 'bar' ] ] ],
		];
	}

	public function teststripQuestionMarks() {
		// tests are input, strippingLevel, expectedOutput
		$tests = [
			[ 'pickles', 'all', 'pickles' ],
			[ 'pic?les', 'all', 'pic les' ],
			[ 'pic?les', 'break', 'pic?les' ],
			[ 'pic?les', 'no', 'pic?les' ],
			[ 'pic?les', 'final', 'pic?les' ],
			[ 'pickle?', 'all', 'pickle ' ],
			[ 'pickle?', 'break', 'pickle' ],
			[ 'მწნილი?', 'no', 'მწნილი?' ],
			[ 'მწნილი?', 'final', 'მწნილი' ],
			[ '?漬物', 'all', ' 漬物' ],
			[ '?漬物', 'break', '?漬物' ],
			[ 'pic? les', 'all', 'pic  les' ],
			[ 'pic? les', 'break', 'pic les' ],
			[ 'pic\?les', 'all', 'pic?les' ],
			[ 'pic\?les', 'break', 'pic?les' ],
			[ 'pic\?les', 'no', 'pic\?les' ],
			[ 'pic\?les', 'final', 'pic?les' ],
			[ 'insource:/x?/', 'all', 'insource:/x?/' ],
			[ 'insource:/x?/', 'break', 'insource:/x?/' ],
			[ 'insource:/x?/', 'no', 'insource:/x?/' ],
			[ 'insource:/x?/', 'final', 'insource:/x?/' ],
			[ '??', 'all', '??' ],
			[ '¿.; ?', 'all', '¿.; ?' ],
		];

		foreach ( $tests as $test ) {
			$this->assertEquals( Util::stripQuestionMarks( $test[0], $test[1] ), $test[2] );
		}
	}

	/**
	 * Produces mock message cache for injecting messages
	 * @return MessageCache
	 */
	private function getMockCache() {
		$mock = $this->getMockBuilder( \MessageCache::class )->disableOriginalConstructor()->getMock();
		$mock->method( 'get' )->willReturnCallback( function ( $key, $useDB, Language $lang ) {
			return "This is $key in {$lang->getCode()}|100%";
		} );
		return $mock;
	}

	/**
	 * Set message cache instance to given object.
	 * TODO: we wouldn't have to do this if we had some proper way to mock message cache.
	 * @param string $class
	 * @param string $var
	 * @param mixed $value
	 */
	private function setPrivateVar( $class, $var, $value ) {
		// nasty hack - reset message cache instance
		$mc = new \ReflectionClass( $class );
		$mcInstance = $mc->getProperty( $var );
		$mcInstance->setAccessible( true );
		$mcInstance->setValue( $value );
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
		$config = new HashSearchConfig( $moreData );
		return $config;
	}

	/**
	 * Put data for a wiki into test cache.
	 * @param \WANObjectCache $cache
	 * @param string $wiki
	 */
	private function putDataIntoCache( \WANObjectCache $cache, $wiki ) {
		$key = $cache->makeGlobalKey( 'cirrussearch-boost-templates', $wiki );
		$cache->set( $key, [ "Data for $wiki" => 2 ] );
	}

	/**
	 * Create test local cache
	 * @return \WANObjectCache
	 */
	private function makeLocalCache() {
		$this->setMwGlobals( [
			'wgMainCacheType' => 'UtilTest',
			'wgObjectCaches' => [ 'UtilTest' => [ 'class' => \HashBagOStuff::class ] ]
		] );
		$services = MediaWikiServices::getInstance();
		$services->resetServiceForTesting( 'MainWANObjectCache' );
		$services->redefineService( 'MainWANObjectCache', function () {
			return new \WANObjectCache( [ 'cache' => new \HashBagOStuff() ] );
		} );

		return $services->getMainWANObjectCache();
	}

	/**
	 * @covers \CirrusSearch\Util::getDefaultBoostTemplates
	 */
	public function testgetDefaultBoostTemplates() {
		$cache = $this->makeLocalCache();
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
	public function testCustomizeBoostTemplatesByConfig() {
		$configValues = [
			'CirrusSearchBoostTemplates' => [
				'Featured' => 2,
			],
		];
		$config = $this->getHashConfig( 'ruwiki', $configValues );
		$ru = Util::getDefaultBoostTemplates( $config );
		$this->assertArrayEquals( $configValues['CirrusSearchBoostTemplates'], $ru );
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
		$cache = $this->makeLocalCache();
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
		$config = $this->getHashConfig( wfWikiID(), $configValues );

		// On wiki config should override config templates
		$cache = $this->makeLocalCache();
		$this->putDataIntoCache( $cache, wfWikiID() );

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

		$cache = $this->makeLocalCache();
		$this->putDataIntoCache( $cache, 'ruwiki' );

		$ru = Util::getDefaultBoostTemplates( $config );
		$this->assertArrayEquals( $configValues['CirrusSearchBoostTemplates'], $ru );
	}

	public function testgetDefaultBoostTemplatesLocal() {
		global $wgContLang;
		$this->setPrivateVar( \MessageCache::class, 'instance', $this->getMockCache() );
		$this->setPrivateVar( Util::class, 'defaultBoostTemplates', null );

		$cache = $this->makeLocalCache();
		$config = $this->getHashConfig( wfWikiID() );
		$key = $cache->makeGlobalKey( 'cirrussearch-boost-templates', $config->getWikiId() );

		// FIXME: we cannot really test the default value for $config
		// with Util::getDefaultBoostTemplates(). It looks like
		// MediaWikiServices initializes the current wiki SearchConfig
		// when wfWikiID() == 'wiki' and then it's cached, the test
		// framework seems to update the wiki name to wiki-unittest_
		// making it impossible to test if we are running on the local
		// wiki.
		// resetting MediaWikiServices would be nice but it does not
		// seem to be trivial.
		$cur = Util::getDefaultBoostTemplates( $config );
		reset( $cur );
		$this->assertContains( ' in ' . $wgContLang->getCode(), key( $cur ) );

		// Check we cached it
		$cached = $cache->get( $key );
		$this->assertNotEmpty( $cached, 'Should cache the value' );
	}

	public function tearDown() {
		// reset cache so that our mock won't pollute other tests
		$this->setPrivateVar( \MessageCache::class, 'instance', null );
		$this->setPrivateVar( Util::class, 'defaultBoostTemplates', null );
		parent::tearDown();
		// Clean ns cache after we restore config vars
		\MWNamespace::clearCaches();
	}

	/**
	 * @dataProvider provideTestIdentifyNamespace
	 * @param string $namespace
	 * @param int|bool $expected
	 */
	public function testIdentifyNamespace( $namespace, $expected, $method ) {
		$this->setMwGlobals( [
			'wgExtraNamespaces' => [
				100 => 'Maçon',
				101 => 'Cédille',
				102 => 'Groß',
				103 => 'Norræn goðafræði',
				104 => 'لَحَم', // لحم
				105 => 'Thảo_luận',
			],
			'wgNamespaceAliases' => [
				'Mañsoner' => 100,
			]
		] );
		$language = new Language();
		\MWNamespace::clearCaches();
		$this->assertEquals( $expected, Util::identifyNamespace( $namespace, $method, $language ) );
	}

	public function provideTestIdentifyNamespace() {
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
}
