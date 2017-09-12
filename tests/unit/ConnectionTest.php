<?php

namespace CirrusSearch;

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
 *
 * @group CirrusSearch
 */
class ConnectionTest extends CirrusTestCase {
	/**
	 * @dataProvider provideNamespacesInIndexType
	 */
	public function testNamespacesInIndexType( $contentNamespaces, $defaultSearchNamespaces, $namespaceMappings, $indexType, $expected ) {
		$config = new HashSearchConfig( [
			'ContentNamespaces' => $contentNamespaces,
			'CirrusSearchNamespaceMappings' => $namespaceMappings,
			'NamespacesToBeSearchedDefault' => $defaultSearchNamespaces,
		], [ 'inherit' ] );
		$conn = new Connection( $config );
		$this->assertEquals( $expected, $conn->namespacesInIndexType( $indexType ) );
	}

	public static function provideNamespacesInIndexType() {
		return [
			// Standard:
			[ [ NS_MAIN ], [ NS_MAIN => true ], [], 'content', 1 ],
			[ [ NS_MAIN ], [ NS_MAIN => true ], [], 'general', false ],

			// Commons:
			[ [ NS_MAIN ], [ NS_MAIN => true ], [ NS_FILE => 'file' ], 'file', 1 ],

			// Funky:
			[ [ NS_MAIN ], [ NS_MAIN => true ], [ NS_FILE => 'file', NS_FILE_TALK => 'file' ], 'file', 2 ],
			[ [ NS_MAIN ], [ NS_MAIN => true ], [ NS_FILE => 'file', NS_FILE_TALK => 'file' ], 'conent', false ],
			[ [ NS_MAIN, NS_FILE ], [ NS_MAIN => true ], [], 'content', 2 ],
			[ [ NS_MAIN, NS_FILE ], [ NS_MAIN => true ], [ NS_FILE => 'file' ], 'file', 1 ],
			[ [ NS_MAIN, NS_FILE ], [ NS_MAIN => true ], [ NS_FILE => 'file' ], 'content', 1 ],
			[ [ NS_MAIN, NS_FILE, NS_FILE_TALK ], [ NS_MAIN => true ], [ NS_FILE => 'file' ], 'content', 2 ],
			[ [ NS_MAIN, NS_FILE, NS_FILE_TALK ], [ NS_MAIN => true ], [], 'content', 3 ],
			[ [ NS_MAIN ], [ NS_MAIN => true, NS_FILE => true ], [ NS_FILE => 'file' ], 'content', 1 ],
			[ [ NS_MAIN ], [ NS_MAIN => true, NS_FILE => true ], [ NS_FILE => 'file' ], 'file', 1 ],
			[ [ NS_MAIN, NS_FILE ], [ NS_MAIN => true, NS_FILE => true ], [ NS_FILE => 'file' ], 'content', 1 ],
			[ [ NS_MAIN, NS_FILE ], [ NS_MAIN => true, NS_FILE => true ], [ NS_FILE => 'file' ], 'file', 1 ],
		];
	}

	public function extractIndexSuffixProvider() {
		return [
			'basic index name' => [
				'content',
				'testwiki_content_first',
			],
			'timestamped index name' => [
				'general',
				'testwiki_general_12345678',
			],
			'indexBaseName with underscore' => [
				'content',
				'test_thiswiki_content_first'
			],
			'handles user defined suffixes' => [
				'file',
				'zomgwiki_file_654321',
			],
		];
	}

	/**
	 * @dataProvider extractIndexSuffixProvider
	 */
	public function testExtractIndexSuffixFromIndexName( $expected, $name ) {
		$config = new HashSearchConfig( [
			'CirrusSearchNamespaceMappings' => [
				NS_FILE => 'file',
			],
			// Needed for constructor to not blow up
			'CirrusSearchServers' => [ 'localhost' ],
		] );
		$conn = new Connection( $config );
		$this->assertEquals( $expected, $conn->extractIndexSuffix( $name ) );
	}

	/**
	 * @expectedException \Exception
	 */
	public function testExtractIndexSuffixThrowsExceptionOnUnknown() {
		$config = new HashSearchConfig( [
			'CirrusSearchNamespaceMappings' => [],
			// Needed for constructor to not blow up
			'CirrusSearchServers' => [ 'localhost' ],
		] );
		$conn = new Connection( $config );
		$conn->extractIndexSuffix( 'testwiki_file_first' );
	}
}
