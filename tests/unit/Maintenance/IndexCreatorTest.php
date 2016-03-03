<?php

namespace CirrusSearch\Tests\Maintenance;

use CirrusSearch\Maintenance\IndexCreator;

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
	public function testCreateIndex( $rebuild, $maxShardsPerNode ) {
		$index = $this->getIndex();
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
		return array(
			array( true, 'unlimited' ),
			array( true, 2 ),
			array( false, 'unlimited' ),
			array( false, 2 )
		);
	}

	private function getIndex() {
		return $this->getMockBuilder( 'Elastica\Index' )
			->disableOriginalConstructor()
			->getMock();
	}

	private function getAnalysisConfigBuilder() {
		return $this->getMockBuilder( 'CirrusSearch\Maintenance\AnalysisConfigBuilder' )
			->disableOriginalConstructor()
			->getMock();
	}
}
