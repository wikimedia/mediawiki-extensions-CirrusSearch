<?php

namespace CirrusSearch;

use CirrusSearch\Test\HashSearchConfig;
use MediaWiki\MediaWikiServices;
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
		$config = MediaWikiServices::getInstance()
			->getConfigFactory()
			->makeConfig( 'CirrusSearch' );
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

	public function extractIndexSuffixProvider() {
		return array(
			'basic index name' => array(
				'content',
				'testwiki_content_first',
			),
			'timestamped index name' => array(
				'general',
				'testwiki_general_12345678',
			),
			'indexBaseName with underscore' => array(
				'content',
				'test_thiswiki_content_first'
			),
			'handles user defined suffixes' => array(
				'file',
				'zomgwiki_file_654321',
			),
		);
	}

	/**
	 * @dataProvider extractIndexSuffixProvider
	 */
	public function testExtractIndexSuffixFromIndexName( $expected, $name ) {
		$config = new HashSearchConfig( array(
			'CirrusSearchNamespaceMappings' => array(
				NS_FILE => 'file',
			),
			// Needed for constructor to not blow up
			'CirrusSearchServers' => array( 'localhost' ),
		) );
		$conn = new Connection( $config );
		$this->assertEquals( $expected, $conn->extractIndexSuffix( $name ) );
	}

	/**
	 * @expectedException \Exception
	 */
	public function testExtractIndexSuffixThrowsExceptionOnUnknown() {
		$config = new HashSearchConfig( array(
			'CirrusSearchNamespaceMappings' => array(),
			// Needed for constructor to not blow up
			'CirrusSearchServers' => array( 'localhost' ),
		) );
		$conn = new Connection( $config );
		$conn->extractIndexSuffix( 'testwiki_file_first' );
	}
}
