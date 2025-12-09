<?php

namespace CirrusSearch;

/**
 * Make sure cirrus doens't break any hooks.
 *
 * @license GPL-2.0-or-later
 *
 * @group CirrusSearch
 * @covers \CirrusSearch\Connection
 */
class ConnectionTest extends CirrusIntegrationTestCase {
	public static function extractIndexSuffixProvider() {
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

	public function testExtractIndexSuffixThrowsExceptionOnUnknown() {
		$config = new HashSearchConfig( [
			'CirrusSearchNamespaceMappings' => [],
			// Needed for constructor to not blow up
			'CirrusSearchServers' => [ 'localhost' ],
		] );
		$conn = new Connection( $config );
		$this->expectException( \Exception::class );
		$conn->extractIndexSuffix( 'testwiki_file_first' );
	}

	public function testCanRemoveArchiveFromAllIndexSuffixes() {
		$con = new Connection( new HashSearchConfig( [
			'CirrusSearchServers' => [ 'localhost' ],
			'CirrusSearchNamespaceMappings' => [],
			'CirrusSearchEnableArchive' => false,
			'CirrusSearchPrivateClusters' => null,
		] ) );
		$this->assertNotContains( Connection::ARCHIVE_INDEX_SUFFIX, $con->getAllIndexSuffixes( null ) );
		$this->assertArrayEquals( [], $con->getAllIndexSuffixes( Connection::ARCHIVE_DOC_TYPE ) );
	}

	public function testGetAllIndexSuffixes() {
		$con = new Connection( new HashSearchConfig( [
			'CirrusSearchServers' => [ 'localhost' ],
			'CirrusSearchNamespaceMappings' => [],
			'CirrusSearchEnableArchive' => true,
			'CirrusSearchPrivateClusters' => null,
		] ) );
		$this->assertArrayEquals( [ Connection::CONTENT_INDEX_SUFFIX, Connection::GENERAL_INDEX_SUFFIX ],
			$con->getAllIndexSuffixes() );
		$this->assertArrayEquals( [ Connection::CONTENT_INDEX_SUFFIX, Connection::GENERAL_INDEX_SUFFIX ],
			$con->getAllIndexSuffixes( Connection::PAGE_DOC_TYPE ) );
		$this->assertArrayEquals( [ Connection::CONTENT_INDEX_SUFFIX, Connection::GENERAL_INDEX_SUFFIX, Connection::ARCHIVE_INDEX_SUFFIX ],
			$con->getAllIndexSuffixes( null ) );
		$this->assertArrayEquals( [ Connection::ARCHIVE_INDEX_SUFFIX ],
			$con->getAllIndexSuffixes( Connection::ARCHIVE_DOC_TYPE ) );

		$con = new Connection( new HashSearchConfig( [
			'CirrusSearchServers' => [ 'localhost' ],
			'CirrusSearchNamespaceMappings' => [ NS_FILE => 'file' ],
			'CirrusSearchEnableArchive' => true,
			'CirrusSearchPrivateClusters' => null,
		] ) );

		$this->assertArrayEquals( [ Connection::CONTENT_INDEX_SUFFIX, Connection::GENERAL_INDEX_SUFFIX, 'file' ],
			$con->getAllIndexSuffixes() );
		$this->assertArrayEquals( [ Connection::CONTENT_INDEX_SUFFIX, Connection::GENERAL_INDEX_SUFFIX, 'file' ],
			$con->getAllIndexSuffixes( Connection::PAGE_DOC_TYPE ) );
		$this->assertArrayEquals(
			[
				Connection::CONTENT_INDEX_SUFFIX,
				Connection::GENERAL_INDEX_SUFFIX,
				Connection::ARCHIVE_INDEX_SUFFIX,
				'file'
			],
			$con->getAllIndexSuffixes( null )
		);
		$this->assertArrayEquals( [ Connection::ARCHIVE_INDEX_SUFFIX ],
			$con->getAllIndexSuffixes( Connection::ARCHIVE_DOC_TYPE ) );
	}

	public static function providePoolCaching() {
		return [
			'constant returns same' => [
				'config' => [
					'CirrusSearchServers' => [ 'localhost:9092' ],
				],
				'update' => [],
			],
			'separate clusters' => [
				'config' => [
					'CirrusSearchDefaultCluster' => 'a',
					'CirrusSearchReplicaGroup' => 'default',
					'CirrusSearchClusters' => [
						'a' => [ 'localhost:9092', 'replica' => 'a' ],
						'b' => [ 'localhost:9192', 'replica' => 'b' ],
					],
				],
				'update' => [
					'CirrusSearchDefaultCluster' => 'b',
				],
			],
			'separate replica groups' => [
				'config' => [
					'CirrusSearchDefaultCluster' => 'ut',
					'CirrusSearchReplicaGroup' => 'a',
					'CirrusSearchClusters' => [
						'a' => [ 'localhost:9092', 'replica' => 'ut', 'group' => 'a' ],
						'b' => [ 'localhost:9192', 'replica' => 'ut', 'group' => 'b' ],
					],
				],
				'update' => [
					'CirrusSearchReplicaGroup' => 'b',
				],
			],
		];
	}

	/**
	 * @dataProvider providePoolCaching
	 */
	public function testPoolCaching( array $config, array $update ) {
		$conn = Connection::getPool( new HashSearchConfig( $config ) );
		$conn2 = Connection::getPool( new HashSearchConfig( $config ) );
		$this->assertEquals( $conn, $conn2 );

		if ( $update ) {
			$conn3 = Connection::getPool( new HashSearchConfig( $update + $config ) );
			$this->assertNotEquals( $conn, $conn3 );
		}
	}
}
