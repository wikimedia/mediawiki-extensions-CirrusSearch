<?php

namespace CirrusSearch\Search;

use CirrusSearch\CirrusTestCase;

/**
 * Test escaping search strings.
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
 * @covers \CirrusSearch\Search\Escaper
 * @group CirrusSearch
 */
class EscaperTest extends CirrusTestCase {

	/**
	 * @dataProvider provideQueriesToFixup
	 */
	public function testFuzzyEscape( $input, $expected ) {
		$escaper = new Escaper( 'unittest' );
		$actual = $escaper->fixupWholeQueryString( $input );
		$this->assertEquals( $expected, $actual );
	}

	public static function provideQueriesToFixup() {
		return [
			// Fuzzy escape
			'Default fuzziness is allowed' => [ 'fuzzy~', 'fuzzy~' ],
			'No fuzziness is allowed' => [ 'fuzzy~0', 'fuzzy~0' ],
			'One char edit distance is allowed' => [ 'fuzzy~1', 'fuzzy~1' ],
			'Two char edit distance is allowed' => [ 'fuzzy~2', 'fuzzy~2' ],
			'Three char edit distance is disallowed' => [ 'fuzzy~3', 'fuzzy\\~3' ],
			'non-integer edit distance is disallowed' => [ 'fuzzy~1.0', 'fuzzy\\~1.0' ],
			'Larger edit distances are disallowed' => [ 'fuzzy~10', 'fuzzy\\~10' ],
			'Proximity searches are allowed' => [ '"fuzzy wuzzy"~10', '"fuzzy wuzzy"~10' ],
			'Float fuzziness with leading 0 is disallowed' => [ 'fuzzy~0.8', 'fuzzy\\~0.8' ],
			'Float fuzziness is disallowed' => [ 'fuzzy~.8', 'fuzzy\\~.8' ],

			// Looks like a boolean operator, but isn't followed by a term
			[ 'SOMETHING AND ', 'SOMETHING and ' ],
			[ 'SOMETHING AND OR', 'SOMETHING AND or' ],
			[ 'X OR', 'X or' ],
			[ 'WHAT NOT', 'WHAT not' ],
			[ 'Q NOT NOT', 'Q NOT not' ],

			// Looks like a boolean operator, but misses a term before
			[ 'OR WHAT NOW', 'or WHAT NOW' ],
			[ ' AND WHAT NOW', ' and WHAT NOW' ],
			[ 'AND OR WHAT NOW', 'and OR WHAT NOW' ],

			// Two boolean operators following each other? Assume higher precedence for the latter.
			[ 'Q NOT NOT Q', 'Q not NOT Q' ],

			// Boolean operators that are (most probably) meant to be boolean operators
			[ 'YOU AND ME', 'YOU AND ME' ],
			[ 'YOU OR ME', 'YOU OR ME' ],
			[ 'BAND OR X', 'BAND OR X' ],
			[ 'Z OR ANDS Z', 'Z OR ANDS Z' ],
			[ 'NOT ME', 'NOT ME' ],

			// These aren't boolean operators
			[ 'BAND', 'BAND' ],
			[ 'NOTME', 'NOTME' ],
			[ 'ANDERSON', 'ANDERSON' ],
		];
	}

	/**
	 * @dataProvider provideQuoteEscapeTestCases
	 */
	public function testQuoteEscape( $language, $input, $expected ) {
		$escaper = new Escaper( $language );
		$actual = $escaper->escapeQuotes( $input );
		$this->assertEquals( $expected, $actual );
	}

	public static function provideQuoteEscapeTestCases() {
		return [
			[ 'en', 'foo', 'foo' ],
			[ 'en', 'fo"o', 'fo"o' ],
			[ 'el', 'fo"o', 'fo"o' ],
			[ 'de', 'fo"o', 'fo"o' ],
			[ 'he', 'מלבי"ם', 'מלבי\"ם' ],
			[ 'he', '"מלבי"', '"מלבי"' ],
			[ 'he', '"מלבי"ם"', '"מלבי\"ם"' ],
			[ 'he', 'מַ"כִּית', 'מַ\"כִּית' ],
			[ 'he', 'הוּא שִׂרְטֵט עַיִ"ן', 'הוּא שִׂרְטֵט עַיִ\"ן' ],
			[ 'he', '"הוּא שִׂרְטֵט עַיִ"ן"', '"הוּא שִׂרְטֵט עַיִ\"ן"' ],
			// If a term is quoted then it must have escaped
			// the gershayim, and we have to respect that.
			[ 'he', '"אומ\\"ץ"', '"אומ\\"ץ"' ],
			// Ideally in the un-quoted case we would still escape
			// the \, but doesn't work.
			// [ 'he', 'אומ\\"ץ', 'אומ\\\\"ץ' ],
		];
	}

	/**
	 * @dataProvider provideBalanceQuotesTestCases
	 */
	public function testBalanceQuotes( $input, $expected ) {
		$escaper = new Escaper( 'en' ); // Language doesn't matter here
		$actual = $escaper->balanceQuotes( $input );
		$this->assertEquals( $expected, $actual );
	}

	public static function provideBalanceQuotesTestCases() {
		return [
			[ 'foo', 'foo' ],
			[ '"foo', '"foo"' ],
			[ '"foo" bar', '"foo" bar' ],
			[ '"foo" ba"r', '"foo" ba"r"' ],
			[ '"foo" ba\\"r', '"foo" ba\\"r' ],
			[ '"foo\\" ba\\"r', '"foo\\" ba\\"r"' ],
			[ '\\"foo\\" ba\\"r', '\\"foo\\" ba\\"r' ],
			[ '"fo\\o bar', '"fo\\o bar"' ],
		];
	}

	/**
	 * @dataProvider provideEscapedSequence
	 * @param string $escaped
	 * @param string $unescaped
	 */
	public function testUnescape( $escaped, $unescaped ) {
		$escaper = new Escaper( 'en', false );
		$this->assertEquals( $unescaped, $escaper->unescape( $escaped ) );
	}

	public static function provideEscapedSequence() {
		return [
			'unchanged' => [ 'foo', 'foo' ],
			'simple' => [ 'foo\\"', 'foo"' ],
			'escaped escape' => [ 'foo\\\\"', 'foo\\"' ],
			'both' => [ 'foo\\\\\\"', 'foo\\"' ],
		];
	}
}
