<?php

namespace CirrusSearch\Assignment;

use CirrusSearch\CirrusTestCase;

/**
 * @covers \CirrusSearch\Assignment\ConstantAssignment
 */
class ConstantAssignmentTest extends CirrusTestCase {
	public function testEverything() {
		$clusters = new ConstantAssignment( [ 'localhost:9200' ] );
		$this->assertEquals( 'default', $clusters->uniqueId( 'anything' ) );
		$this->assertEquals( 'default', $clusters->getSearchCluster() );
		$this->assertEquals( [ 'default' ], $clusters->getWritableClusters( 'anything' ) );
		$this->assertEquals( [ 'default' ], $clusters->getManagedClusters() );
		$this->assertEquals( [ 'localhost:9200' ], $clusters->getServerList() );
		$this->assertEquals( [ 'localhost:9200' ], $clusters->getServerList( 'default' ) );
		$this->assertNull( $clusters->getCrossClusterName() );
	}
}
