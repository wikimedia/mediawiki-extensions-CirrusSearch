<?php

namespace CirrusSearch;

use ConfigFactory;
use PHPUnit_Framework_TestCase;

/**
 * Make sure cirrus doens't break any hooks.
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
class ConnectionTest extends PHPUnit_Framework_TestCase {
	/**
	 * @dataProvider provideNamespacesInIndexType
	 */
	public function testNamespacesInIndexType( $contentNamespaces, $namespaceMappings, $indexType, $expected ) {
		global $wgContentNamespaces,
			$wgCirrusSearchNamespaceMappings;

		$wgContentNamespaces = $contentNamespaces;
		$wgCirrusSearchNamespaceMappings = $namespaceMappings;
		$config = ConfigFactory::getDefaultInstance()->makeConfig( 'CirrusSearch' );
		$conn = new Connection( $config );
		$this->assertEquals( $expected, $conn->namespacesInIndexType( $indexType ) );
	}

	public static function provideNamespacesInIndexType() {
		return array(
			// Standard:
			array( array( NS_MAIN ), array(), 'content', 1 ),
			array( array( NS_MAIN ), array(), 'general', false ),

			// Commons:
			array( array( NS_MAIN ), array( NS_FILE => 'file' ), 'file', 1 ),

			// Funky:
			array( array( NS_MAIN ), array( NS_FILE => 'file', NS_FILE_TALK => 'file' ), 'file', 2 ),
			array( array( NS_MAIN ), array( NS_FILE => 'file', NS_FILE_TALK => 'file' ), 'conent', false ),
			array( array( NS_MAIN, NS_FILE ), array(), 'content', 2 ),
			array( array( NS_MAIN, NS_FILE ), array( NS_FILE => 'file' ), 'file', 1 ),
			array( array( NS_MAIN, NS_FILE ), array( NS_FILE => 'file' ), 'content', 1 ),
			array( array( NS_MAIN, NS_FILE, NS_FILE_TALK ), array( NS_FILE => 'file' ), 'content', 2 ),
			array( array( NS_MAIN, NS_FILE, NS_FILE_TALK ), array(), 'content', 3 ),
		);
	}
}
