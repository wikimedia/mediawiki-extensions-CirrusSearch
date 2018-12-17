<?php

namespace CirrusSearch\Assignment;

/**
 * @covers \CirrusSearch\Assignment\ConstantAssignment
 */
class ConstantAssignmentTest extends \MediaWikiTestCase {
	public function testEverything() {
		$clusters = new ConstantAssignment( [ 'localhost:9200' ] );
		$this->assertEquals( 'default', $clusters->getSearchCluster() );
		$this->assertEquals( [ 'default' ], $clusters->getWritableClusters() );
		$this->assertEquals( [ 'localhost:9200' ], $clusters->getServerList() );
		$this->assertEquals( [ 'localhost:9200' ], $clusters->getServerList( 'default' ) );
		$this->assertNull( $clusters->getCrossClusterName() );
	}
}
