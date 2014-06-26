<?php

namespace CirrusSearch;

use \MediaWikiTestCase;

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
class SearchEscaperTest extends MediaWikiTestCase {
	/**
	 * @dataProvider quoteEscapeTestCases
	 */
	public function testQuoteEscape( $language, $input, $expected ) {
		$escaper = new SearchEscaper( $language );
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
		$escaper = new SearchEscaper( 'en' ); // Language doesn't matter here
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
