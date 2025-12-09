<?php

namespace CirrusSearch\Tests\Maintenance;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\Maintenance\ConfigUtils;
use CirrusSearch\Maintenance\IndexCreator;
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
			'0-2', // replicaCount
			30, // refreshInterval
			[], // mergeSettings
			[] // extra index settings
		);

		$this->assertInstanceOf( Status::class, $status );
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

		$index->method( 'create' )
			->willReturn( $response );

		return $index;
	}
}
