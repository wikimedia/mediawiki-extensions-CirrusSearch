<?php

namespace CirrusSearch;

use \MediaWikiTestCase;

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
}
