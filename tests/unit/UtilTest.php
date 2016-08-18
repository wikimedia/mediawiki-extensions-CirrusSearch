<?php

namespace CirrusSearch;

use CirrusSearch\Test\HashSearchConfig;
use MediaWiki\MediaWikiServices;
use MediaWikiTestCase;
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
 */
class UtilTest extends MediaWikiTestCase {
	/**
	 * @dataProvider recursiveSameTestCases
	 */
	public function testRecursiveSame( $same, $lhs, $rhs ) {
		$this->assertEquals( $same, Util::recursiveSame( $lhs, $rhs ) );
	}

	public static function recursiveSameTestCases() {
		return array(
			array( true, array(), array() ),
			array( false, array( true ), array() ),
			array( false, array( true ), array( false ) ),
			array( true, array( true ), array( true ) ),
			array( false, array( 1 ), array( 2 ) ),
			array( false, array( 1, 2 ), array( 2, 1 ) ),
			array( true, array( 1, 2, 3 ), array( 1, 2, 3 ) ),
			array( false, array( array( 1 ) ), array( array( 2 ) ) ),
			array( true, array( array( 1 ) ), array( array( 1 ) ) ),
			array( true, array( 'candle' => array( 'wax' => 'foo' ) ), array( 'candle' => array( 'wax' => 'foo' ) ) ),
			array( false, array( 'candle' => array( 'wax' => 'foo' ) ), array( 'candle' => array( 'wax' => 'bar' ) ) ),
		);
	}

	/**
	 * @dataProvider cleanUnusedFieldsProvider
	 */
	public function testCleanUnusedFields( $data, $properties, $expect ) {
		$result = Util::cleanUnusedFields( $data, $properties );
		$this->assertArrayEquals( $result, $expect );
	}

	public static function cleanUnusedFieldsProvider() {
		return array(
			// sample
			array(
				// data
				array(
					'title' => "I'm a title",
					'useless' => "I'm useless",
				),
				// properties
				array(
					'title' => 'params-for-title'
				),
				// expect
				array(
					'title' => "I'm a title",
				),
			),
			// Flow data - untouched
			array(
				// data (as seen in https://gerrit.wikimedia.org/r/#/c/195889/1//COMMIT_MSG)
				array(
					'namespace' => 1,
					'namespace_text' => "Talk",
					'pageid' => 2,
					'title' => "Main Page",
					'timestamp' => "2014-02-07T01:42:57Z",
					'update_timestamp' => "2014-02-25T14:12:40Z",
					'revisions' => array(
						array(
							'id' => "rpvwvywl9po7ih77",
							'text' => "topic title content",
							'source_text' => "topic title content",
							'moderation_state' => "",
							'timestamp' => "2014-02-07T01:42:57Z",
							'update_timestamp' => "2014-02-07T01:42:57Z",
							'type' => "topic"
						),
						array(
							'id' => "ropuzninqgyf19ko",
							'text' => "reply content",
							'source_text' => "reply '''content'''",
							'moderation_state' => "hide",
							'timestamp' => "2014-02-25T14:12:40Z",
							'update_timestamp' => "2014-02-25T14:12:40Z",
							'type' => "post"
						),
					)
				),
				// properties (as seen in https://gerrit.wikimedia.org/r/#/c/161251/26/includes/Search/maintenance/MappingConfigBuilder.php)
				array(
					'namespace' => array( '...' ),
					'namespace_text' => array( '...' ),
					'pageid' => array( '...' ),
					'title' => array( '...' ),
					'timestamp' => array( '...' ),
					'update_timestamp' => array( '...' ),
					'revisions' => array(
						'properties' => array(
							'id' => array( '...' ),
							'text' => array( '...' ),
							'source_text' => array( '...' ),
							'moderation_state' => array( '...' ),
							'timestamp' => array( '...' ),
							'update_timestamp' => array( '...' ),
							'type' => array( '...' ),
						)
					),
				),
				// expect
				array(
					'namespace' => 1,
					'namespace_text' => "Talk",
					'pageid' => 2,
					'title' => "Main Page",
					'timestamp' => "2014-02-07T01:42:57Z",
					'update_timestamp' => "2014-02-25T14:12:40Z",
					'revisions' => array(
						array(
							'id' => "rpvwvywl9po7ih77",
							'text' => "topic title content",
							'source_text' => "topic title content",
							'moderation_state' => "",
							'timestamp' => "2014-02-07T01:42:57Z",
							'update_timestamp' => "2014-02-07T01:42:57Z",
							'type' => "topic"
						),
						array(
							'id' => "ropuzninqgyf19ko",
							'text' => "reply content",
							'source_text' => "reply '''content'''",
							'moderation_state' => "hide",
							'timestamp' => "2014-02-25T14:12:40Z",
							'update_timestamp' => "2014-02-25T14:12:40Z",
							'type' => "post"
						),
					)
				),
			),
			// Flow data - deleted columns in config
			array(
				// data (as seen in https://gerrit.wikimedia.org/r/#/c/195889/1//COMMIT_MSG)
				array(
					'namespace' => 1,
					'namespace_text' => "Talk",
					'pageid' => 2,
					'title' => "Main Page",
					'timestamp' => "2014-02-07T01:42:57Z",
					'update_timestamp' => "2014-02-25T14:12:40Z",
					'revisions' => array(
						array(
							'id' => "rpvwvywl9po7ih77",
							'text' => "topic title content",
							'source_text' => "topic title content",
							'moderation_state' => "",
							'timestamp' => "2014-02-07T01:42:57Z",
							'update_timestamp' => "2014-02-07T01:42:57Z",
							'type' => "topic"
						),
						array(
							'id' => "ropuzninqgyf19ko",
							'text' => "reply content",
							'source_text' => "reply '''content'''",
							'moderation_state' => "hide",
							'timestamp' => "2014-02-25T14:12:40Z",
							'update_timestamp' => "2014-02-25T14:12:40Z",
							'type' => "post"
						),
					)
				),
				// properties (as seen in https://gerrit.wikimedia.org/r/#/c/161251/26/includes/Search/maintenance/MappingConfigBuilder.php)
				array(
					'namespace' => array( '...' ),
					'namespace_text' => array( '...' ),
					'pageid' => array( '...' ),
					'title' => array( '...' ),
					// deleted timestamp & update_timestamp columns
					'revisions' => array(
						'properties' => array(
							'id' => array( '...' ),
							'text' => array( '...' ),
							'source_text' => array( '...' ),
							'moderation_state' => array( '...' ),
							// deleted timestamp & update_timestamp columns
							'type' => array( '...' ),
						)
					),
				),
				// expect
				array(
					'namespace' => 1,
					'namespace_text' => "Talk",
					'pageid' => 2,
					'title' => "Main Page",
					// deleted timestamp & update_timestamp columns
					'revisions' => array(
						array(
							'id' => "rpvwvywl9po7ih77",
							'text' => "topic title content",
							'source_text' => "topic title content",
							'moderation_state' => "",
							// deleted timestamp & update_timestamp columns
							'type' => "topic"
						),
						array(
							'id' => "ropuzninqgyf19ko",
							'text' => "reply content",
							'source_text' => "reply '''content'''",
							'moderation_state' => "hide",
							// deleted timestamp & update_timestamp columns
							'type' => "post"
						),
					)
				),
			),
		);
	}

	public function testChooseBestRedirect() {
		$convert = function( $x ) {
			$redirect = array();
			foreach( $x as $t ) {
				$redirect[] = array( 'title' => $t, 'namespace' => 0 );
			}
			return $redirect;
		};
		$input = $convert( array( 'Al. Einstein', 'Albert Einstein', 'A. Einstein', 'Einstein, Albert' ) );
		$this->assertEquals( 'Al. Einstein', Util::chooseBestRedirect( 'a', $input ) );
		$this->assertEquals( 'Al. Einstein', Util::chooseBestRedirect( 'al', $input ) );
		$this->assertEquals( 'Albert Einstein', Util::chooseBestRedirect( 'albet', $input ) );
		$this->assertEquals( 'Einstein, Albert', Util::chooseBestRedirect( 'Einstein', $input ) );
		$this->assertEquals( 'Einstein, Albert', Util::chooseBestRedirect( 'Ens', $input ) );
	}

	public function teststripQuestionMarks() {
		// tests are input, strippingLevel, expectedOutput
		$tests = [ [ 'pickles', 'all', 'pickles' ],
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
		$mock = $this->getMockBuilder( 'MessageCache' )->disableOriginalConstructor()->getMock();
		$mock->method( 'get' )->willReturnCallback( function ( $key, $useDB, Language $lang ) {
			return "This is $key in {$lang->getCode()}|100%";
		} );
		return $mock;
	}

	/**
	 * Set message cache instance to given object.
	 * TODO: we wouldn't have to do this if we had some proper way to mock message cache.
	 * @param $class
	 * @param $var
	 * @param $value
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
	 * @param $wiki
	 * @return HashSearchConfig
	 */
	private function getHashConfig( $wiki ) {
		$config = new HashSearchConfig([
			'_wikiID' => $wiki
		]);
		return $config;
	}

	/**
	 * Put data for a wiki into test cache.
	 * @param \BagOStuff $cache
	 * @param            $wiki
	 */
	private function putDataIntoCache( \BagOStuff $cache, $wiki ) {
		$key = $cache->makeGlobalKey( 'cirrussearch-boost-templates', $wiki );
		$cache->set( $key, "Data for $wiki" );
	}

	/**
	 * Create test local cache
	 * @return \BagOStuff
	 */
	private function makeLocalCache() {
		$this->setMwGlobals( [
			'wgMainCacheType' => 'UtilTest',
			'wgObjectCaches' => [ 'UtilTest' => [ 'class' => \HashBagOStuff::class ] ]
		] );

		return \ObjectCache::getLocalClusterInstance();
	}

	/**
	 * @covers Utils::getDefaultBoostTemplates
	 */
	public function testgetDefaultBoostTemplates() {
		$cache = $this->makeLocalCache();
		$this->putDataIntoCache( $cache, 'ruwiki' );
		$this->putDataIntoCache( $cache, 'cywiki' );

		$cy = Util::getDefaultBoostTemplates( $this->getHashConfig( 'cywiki' ) );
		$ru = Util::getDefaultBoostTemplates( $this->getHashConfig( 'ruwiki' ) );

		$this->assertNotEquals( $cy, $ru, 'Boosts should change with language' );

		// no cache means empty array
		$this->assertArrayEquals( [ ],
			Util::getDefaultBoostTemplates( $this->getHashConfig( 'hywiki' ) ) );

	}

	public function testgetDefaultBoostTemplatesLocal() {
		global $wgContLang;
		$this->setPrivateVar( \MessageCache::class, 'instance', $this->getMockCache() );
		$this->setPrivateVar( Util::class, 'defaultBoostTemplates', null );

		$cache = $this->makeLocalCache();
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'CirrusSearch' );
		$key = $cache->makeGlobalKey( 'cirrussearch-boost-templates', $config->getWikiId() );

		$cur = Util::getDefaultBoostTemplates();
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
	}
}
