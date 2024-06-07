<?php

namespace CirrusSearch\Sanity;

use CirrusSearch\CirrusTestCase;
use InvalidArgumentException;

/**
 * @covers \CirrusSearch\Sanity\MultiClusterRemediatorHelper
 */
class MultiClusterRemediatorHelperTest extends CirrusTestCase {

	public function testSendOptimized() {
		$wp = $this->createNoOpMock( \WikiPage::class );

		$r1 = $this->createNoOpMock( Remediator::class );
		$r2 = $this->createNoOpMock( Remediator::class );

		$allClustersRemediator = $this->createMock( AllClustersQueueingRemediator::class );
		$allClustersRemediator->method( 'canSendOptimizedJob' )
			->with( [ 'c1', 'c2' ] )
			->willReturn( true );
		$allClustersRemediator->expects( $this->once() )
			->method( 'redirectInIndex' )
			->with( $wp );
		$b1 = new BufferedRemediator();
		$b2 = new BufferedRemediator();

		$helper = new MultiClusterRemediatorHelper( [ 'c1' => $r1, 'c2' => $r2 ], [ 'c1' => $b1, 'c2' => $b2 ],
			$allClustersRemediator );
		$b1->redirectInIndex( $wp );
		$b2->redirectInIndex( $wp );
		$helper->sendBatch();
	}

	public function testSendUnoptimized() {
		$wp = $this->createNoOpMock( \WikiPage::class );

		$r1 = $this->createMock( Remediator::class );
		$r1->expects( $this->once() )
			->method( 'redirectInIndex' )
			->with( $wp );
		$r2 = $this->createMock( Remediator::class );
		$r2->expects( $this->once() )
			->method( 'pageNotInIndex' )
			->with( $wp );

		$allClustersRemediator = $this->createNoOpMock( AllClustersQueueingRemediator::class );

		$b1 = new BufferedRemediator();
		$b2 = new BufferedRemediator();

		$helper = new MultiClusterRemediatorHelper( [ 'c1' => $r1, 'c2' => $r2 ], [ 'c1' => $b1, 'c2' => $b2 ],
			$allClustersRemediator );
		$b1->redirectInIndex( $wp );
		$b2->pageNotInIndex( $wp );
		$helper->sendBatch();
	}

	public function testNotSimilarClusters() {
		$wp = $this->createNoOpMock( \WikiPage::class );

		$r1 = $this->createMock( Remediator::class );
		$r1->expects( $this->once() )
			->method( 'redirectInIndex' )
			->with( $wp );

		$allClustersRemediator = $this->createNoOpMock( AllClustersQueueingRemediator::class,
			[ 'canSendOptimizedJob' ] );

		$allClustersRemediator->method( 'canSendOptimizedJob' )
			->with( [ 'c1' ] )
			->willReturn( false );

		$b1 = new BufferedRemediator();

		$helper = new MultiClusterRemediatorHelper( [ 'c1' => $r1 ], [ 'c1' => $b1 ], $allClustersRemediator );
		$b1->redirectInIndex( $wp );
		$helper->sendBatch();
	}

	public function testBadCtorParams() {
		$r1 = $this->createNoOpMock( Remediator::class );
		$r2 = $this->createNoOpMock( Remediator::class );
		$allClustersRemediator = $this->createNoOpMock( AllClustersQueueingRemediator::class );

		$b1 = new BufferedRemediator();
		$b2 = new BufferedRemediator();

		$this->expectException( InvalidArgumentException::class );
		/** @var AllClustersQueueingRemediator $allClustersRemediator */
		new MultiClusterRemediatorHelper( [ 'c1' => $r1, 'c3' => $r2 ], [ 'c1' => $b1, 'c2' => $b2 ], $allClustersRemediator );
	}
}
