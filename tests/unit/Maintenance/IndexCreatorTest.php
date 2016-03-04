<?php

namespace CirrusSearch\Tests\Maintenance;

use CirrusSearch\Maintenance\IndexCreator;
use Elastica\Response;

/**
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
 *
 * @covers CirrusSearch\Maintenance\IndexCreator
 */
class IndexCreatorTest extends \PHPUnit_Framework_TestCase {

	/**
	 * @dataProvider createIndexProvider
	 */
	public function testCreateIndex( $rebuild, $maxShardsPerNode, Response $response ) {
		$index = $this->getIndex( $response );
		$analysisConfigBuilder = $this->getAnalysisConfigBuilder();

		$indexCreator = new IndexCreator( $index, $analysisConfigBuilder );

		$status = $indexCreator->createIndex(
			$rebuild,
			$maxShardsPerNode,
			4, // shardCount
			'0-2', // replicaCount
			30, // refreshInterval
			array(), // mergeSettings
			true // searchAllFields
		);

		$this->assertInstanceOf( 'Status', $status );
	}

	public function createIndexProvider() {
		$successResponse = new Response( array() );
		$errorResponse = new Response( array( 'error' => 'index creation failed' ) );

		return array(
			array( true, 'unlimited', $successResponse ),
			array( true, 2, $successResponse ),
			array( true, 2, $errorResponse ),
			array( false, 'unlimited', $successResponse ),
			array( false, 2, $successResponse ),
			array( false, 'unlimited', $errorResponse )
		);
	}

	private function getIndex( $response ) {
		$index = $this->getMockBuilder( 'Elastica\Index' )
			->disableOriginalConstructor()
			->getMock();

		$index->expects( $this->any() )
			->method( 'create' )
			->will( $this->returnValue( $response ) );

		return $index;
	}

	private function getAnalysisConfigBuilder() {
		return $this->getMockBuilder( 'CirrusSearch\Maintenance\AnalysisConfigBuilder' )
			->disableOriginalConstructor()
			->getMock();
	}
}
