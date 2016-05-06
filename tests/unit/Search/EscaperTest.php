<?php

namespace CirrusSearch\Search;

use PHPUnit_Framework_TestCase;

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
 */
class EscaperTest extends PHPUnit_Framework_TestCase {

	/**
	 * @dataProvider fuzzyEscapeTestCases
	 */
	public function testFuzzyEscape( $input, $expected, $isFuzzy ) {
		$escaper = new Escaper( 'unittest' );
		$actual = $escaper->fixupWholeQueryString( $input );
		$this->assertEquals( array( $expected, $isFuzzy), $actual );
	}

	public static function fuzzyEscapeTestCases() {
		return array(
			'Default fuzziness is allowed' => array( 'fuzzy~', 'fuzzy~', true ),
			'No fuzziness is allowed' => array( 'fuzzy~0', 'fuzzy~0', true ),
			'One char edit distance is allowed' => array( 'fuzzy~1', 'fuzzy~1', true ),
			'Two char edit distance is allowed' => array( 'fuzzy~2', 'fuzzy~2', true ),
			'Three char edit distance is disallowed' => array( 'fuzzy~3', 'fuzzy\\~3', false ),
			'non-integer edit distance is disallowed' => array( 'fuzzy~1.0', 'fuzzy\\~1.0', false ),
			'Larger edit distances are disallowed' => array( 'fuzzy~10', 'fuzzy\\~10', false ),
			'Proximity searches are allowed' => array( '"fuzzy wuzzy"~10', '"fuzzy wuzzy"~10', false ),
			'Float fuzziness with leading 0 is disallowed' => array( 'fuzzy~0.8', 'fuzzy\\~0.8', false ),
			'Float fuzziness is disallowed' => array( 'fuzzy~.8', 'fuzzy\\~.8', false ),
		);
	}

	/**
	 * @dataProvider quoteEscapeTestCases
	 */
	public function testQuoteEscape( $language, $input, $expected ) {
		$escaper = new Escaper( $language );
		$actual = $escaper->escapeQuotes( $input );
		$this->assertEquals( $expected, $actual );
	}

	public static function quoteEscapeTestCases() {
		return array(
			array( 'en', 'foo', 'foo' ),
			array( 'en', 'fo"o', 'fo"o' ),
			array( 'el', 'fo"o', 'fo"o' ),
			array( 'de', 'fo"o', 'fo"o' ),
			array( 'he', 'מלבי"ם', 'מלבי\"ם' ),
			array( 'he', '"מלבי"', '"מלבי"' ),
			array( 'he', '"מלבי"ם"', '"מלבי\"ם"' ),
			array( 'he', 'מַ"כִּית', 'מַ\"כִּית' ),
			array( 'he', 'הוּא שִׂרְטֵט עַיִ"ן', 'הוּא שִׂרְטֵט עַיִ\"ן' ),
			array( 'he', '"הוּא שִׂרְטֵט עַיִ"ן"', '"הוּא שִׂרְטֵט עַיִ\"ן"' ),
		);
	}

	/**
	 * @dataProvider balanceQuotesTestCases
	 */
	public function testBalanceQuotes( $input, $expected ) {
		$escaper = new Escaper( 'en' ); // Language doesn't matter here
		$actual = $escaper->balanceQuotes( $input);
		$this->assertEquals( $expected, $actual );
	}

	public static function balanceQuotesTestCases() {
		return array(
			array( 'foo', 'foo' ),
			array( '"foo', '"foo"' ),
			array( '"foo" bar', '"foo" bar' ),
			array( '"foo" ba"r', '"foo" ba"r"' ),
			array( '"foo" ba\\"r', '"foo" ba\\"r' ),
			array( '"foo\\" ba\\"r', '"foo\\" ba\\"r"' ),
			array( '\\"foo\\" ba\\"r', '\\"foo\\" ba\\"r' ),
			array( '"fo\\o bar', '"fo\\o bar"' ),
		);
	}
}
