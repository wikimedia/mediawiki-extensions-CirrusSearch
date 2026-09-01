<?php

namespace CirrusSearch\Tests\Maintenance;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\Maintenance\ConfigUtils;
use CirrusSearch\Maintenance\IndexCreator;
use CirrusSearch\ReplicaCount;
use Elastica\Index;
use Elastica\Response;
use MediaWiki\Status\Status;

/**
 * @license GPL-2.0-or-later
 *
 * @group CirrusSearch
 *
 * @covers \CirrusSearch\Maintenance\IndexCreator
 */
class IndexCreatorTest extends CirrusTestCase {

	/**
	 * @dataProvider createIndexProvider
	 */
	public function testCreateIndex( $rebuild, $maxShardsPerNode, Response $response ) {
		$index = $this->getIndex( $response );
		$utils = $this->createMock( ConfigUtils::class );
		$utils->method( 'waitForGreen' )
			->willReturn( $this->arrayAsGenerator( [], true ) );

		$indexCreator = new IndexCreator( $index, $utils, [], [], [] );

		$status = $indexCreator->createIndex(
			$rebuild,
			$maxShardsPerNode,
			4, // shardCount
			ReplicaCount::autoExpand( '0-2' ),
			30, // refreshInterval
			[], // mergeSettings
			[] // extra index settings
		);

		$this->assertInstanceOf( Status::class, $status );
	}

	public static function replicaSettingsProvider() {
		return [
			'a range is applied as auto_expand_replicas' => [
				ReplicaCount::autoExpand( '0-2' ),
				[ 'auto_expand_replicas' => '0-2' ],
			],
			'a fixed count is applied as number_of_replicas' => [
				ReplicaCount::fixed( 2 ),
				[ 'number_of_replicas' => 2 ],
			],
		];
	}

	/**
	 * @dataProvider replicaSettingsProvider
	 */
	public function testReplicaSettings( ReplicaCount $replicaCount, array $expected ) {
		$args = null;
		$index = $this->createMock( Index::class );
		$index->method( 'create' )
			->willReturnCallback( static function ( array $createArgs ) use ( &$args ) {
				$args = $createArgs;
				return new Response( [] );
			} );
		$utils = $this->createMock( ConfigUtils::class );
		$utils->method( 'waitForGreen' )
			->willReturn( $this->arrayAsGenerator( [], true ) );

		$indexCreator = new IndexCreator( $index, $utils, [], [], [] );
		$indexCreator->createIndex( false, 'unlimited', 4, $replicaCount, 30, [], [] );

		$replicaSettings = array_intersect_key(
			$args['settings']['index'],
			[ 'auto_expand_replicas' => true, 'number_of_replicas' => true ]
		);
		$this->assertSame( $expected, $replicaSettings );
	}

	public function testCreateIndexUsesConfiguredGreenTimeout() {
		$index = $this->getIndex( new Response( [] ) );
		$utils = $this->createMock( ConfigUtils::class );
		$utils->expects( $this->once() )
			->method( 'waitForGreen' )
			->with( 'test-index', 800 )
			->willReturn( $this->arrayAsGenerator( [], true ) );

		$indexCreator = new IndexCreator( $index, $utils, [], [], [], 800 );
		$indexCreator->createIndex( true, 2, 4, ReplicaCount::autoExpand( '0-2' ), 30, [], [] );
	}

	private function arrayAsGenerator( array $array, $retval ) {
		foreach ( $array as $value ) {
			yield $value;
		}
		return $retval;
	}

	public static function createIndexProvider() {
		$successResponse = new Response( [] );
		$errorResponse = new Response( [ 'error' => 'index creation failed' ] );

		return [
			[ true, 'unlimited', $successResponse ],
			[ true, 2, $successResponse ],
			[ true, 2, $errorResponse ],
			[ false, 'unlimited', $successResponse ],
			[ false, 2, $successResponse ],
			[ false, 'unlimited', $errorResponse ]
		];
	}

	private function getIndex( $response ) {
		$index = $this->createMock( Index::class );
		$index->method( 'getName' )
			->willReturn( 'test-index' );

		$index->method( 'create' )
			->willReturn( $response );

		return $index;
	}
}
