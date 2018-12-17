<?php

namespace CirrusSearch;

use Title;

class OtherIndexesTest extends \PHPUnit\Framework\TestCase {

	public function getExternalIndexesProvider() {
		return [
			'empty config must return empty external indexes' => [
				[ 'Main_Page' => [] ],
				[],
			],
			'config for NS_FILE should only return values for NS_FILE' => [
				[
					'Main_Page' => [],
					'File:Foo' => [ 'zomg' ],
				],
				[ NS_FILE => [ 'zomg' ] ],
			],
		];
	}

	/**
	 * @covers CirrusSearch\OtherIndexes::getExternalIndexes
	 * @dataProvider getExternalIndexesProvider
	 */
	public function testGetExternalIndexes( $assertions, $extraIndexes ) {
		$config = new HashSearchConfig( [
			'CirrusSearchExtraIndexes' => $extraIndexes,
			'CirrusSearchReplicaGroup' => 'default',
		] );

		foreach ( $assertions as $title => $expectedIndices ) {
			$found = array_map(
				function ( $other ) {
					return $other->getSearchIndex( 'default' );
				},
				OtherIndexes::getExternalIndexes( $config, Title::newFromText( $title ) )
			);

			$this->assertEquals( $expectedIndices, $found );
		}
	}

	public function getExtraIndexesForNamespaceProvider() {
		return [
			'Unconfigured does not issue warnings' => [
				[
					[ [ NS_MAIN ], [] ],
				],
				[]
			],
			'Includes configured namespaces' => [
				[
					[ [ NS_MAIN ], [] ],
					[ [ NS_MAIN, NS_FILE ], [ 'zomg' ] ],
					[ [ NS_FILE ], [ 'zomg' ] ],
				],
				[
					NS_FILE => [ 'zomg' ],
				]
			],
		];
	}

	/**
	 * @covers CirrusSearch\OtherIndexes::getExtraIndexesForNamespaces
	 * @dataProvider getExtraIndexesForNamespaceProvider
	 */
	public function testGetExtraIndexesForNamespace( $assertions, $extraIndexes ) {
		$config = new HashSearchConfig( [
			'CirrusSearchExtraIndexes' => $extraIndexes,
			'CirrusSearchReplicaGroup' => 'default',
		] );

		foreach ( $assertions as $assertion ) {
			list( $namespaces, $indices ) = $assertion;
			$found = array_map(
				function ( $other ) {
					return $other->getSearchIndex( 'default' );
				},
				OtherIndexes::getExtraIndexesForNamespaces( $config, $namespaces )
			);
			$this->assertEquals( $indices, $found );
		}
	}

}
