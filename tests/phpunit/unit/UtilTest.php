<?php

namespace CirrusSearch;

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
	 * @covers \CirrusSearch\Util::getDefaultBoostTemplates
	 */
	public function testCustomizeBoostTemplatesByConfig() {
		$configValues = [
			'CirrusSearchBoostTemplates' => [
				'Featured' => 2,
			],
			'CirrusSearchIgnoreOnWikiBoostTemplates' => true,
		];
		$config = $this->getHashConfig( 'ruwiki', $configValues );
		$ru = Util::getDefaultBoostTemplates( $config );
		$this->assertEquals( $configValues['CirrusSearchBoostTemplates'], $ru );
	}

	/**
	 * @covers \CirrusSearch\Util::isEmpty
	 */
	public function testIsEmpty() {
		$this->assertTrue( Util::isEmpty( "" ) );
		$this->assertTrue( Util::isEmpty( [] ) );
		$this->assertTrue( Util::isEmpty( (object)[] ) );
		$this->assertTrue( Util::isEmpty( null ) );
		$this->assertTrue( !Util::isEmpty( 0 ) );
		$this->assertTrue( !Util::isEmpty( false ) );
	}

	/**
	 * @covers \CirrusSearch\Util::setIfDefined
	 */
	public function testSetIfDefined() {
		$arr1 = [ 'KEY1' => '123', 'KEY2' => 0, 'KEY4' => 'a,b,c' ];
		$arr2 = [];

		// Should set, rename key, and cast to int
		Util::setIfDefined( $arr1, 'KEY1', $arr2, 'key1', 'intval' );
		$this->assertEquals( [ 'key1' => 123 ], $arr2 );

		// Should set, not rename key, and cast to boolean
		Util::setIfDefined( $arr1, 'KEY2', $arr2, 'KEY2', 'boolval' );
		$this->assertEquals( [ 'key1' => 123, 'KEY2' => false ], $arr2 );

		// Should not set anything because key3 is not defined in $arr1
		Util::setIfDefined( $arr1, 'KEY3', $arr2, 'key3', 'strval' );
		$this->assertEquals( [ 'key1' => 123, 'KEY2' => false ], $arr2 );

		// Should set, rename key, and explode csv string into array via anon function
		Util::setIfDefined( $arr1, 'KEY4', $arr2, 'key4', static function ( $v ) {
			return explode( ',', $v );
		} );
		$this->assertEquals( [ 'key1' => 123, 'KEY2' => false, 'key4' => [ 'a', 'b', 'c' ] ], $arr2 );
	}

	public static function lookslikeAutomationProvider() {
		return [
			'no config, no problem' => [
				false, null, [], '1.2.3.4', 'some ua'
			],
			'ua match' => [
				true, '/HeadlessChrome/', [], '1.2.3.4', 'Mozilla/1.2 HeadlessChrome/8.7.6'
			],
			'no ua match' => [
				false, '/HeadlessChrome/', [], '1.2.3.4', 'Mozilla/3.4 Chrome/5.4.3'
			],
			'cidr ipv4 match' => [
				true, null, [ '1.0.0.0/8' ], '1.2.3.4', 'another ua'
			],
			'cidr ipv4 no match' => [
				false, null, [ '1.0.0.0/8' ], '4.3.2.1', 'another ua'
			],
			'cidr ipv6 match' => [
				true, null, [ '1:2::/32' ], '1:2:3::4', 'another ua'
			],
			'cidr ipv6 no match' => [
				false, null, [ '1:2::/32' ], '4:3:2::1', 'another ua'
			],
		];
	}

	/**
	 * @dataProvider looksLikeAutomationProvider
	 */
	public function testLooksLikeAutomation( bool $expect, ?string $uaPattern, array $ranges, string $ip, string $ua ) {
		$config = new HashSearchConfig( [
			'CirrusSearchAutomationUserAgentRegex' => $uaPattern,
			'CirrusSearchAutomationCIDRs' => $ranges,
		] );
		$this->assertSame( $expect, Util::looksLikeAutomation( $config, $ip, $ua ) );
	}
}
