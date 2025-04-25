<?php

namespace CirrusSearch\Sanity;

use CirrusSearch\CirrusTestCase;
use InvalidArgumentException;
use MediaWiki\Page\WikiPage;

/**
 * @covers \CirrusSearch\Sanity\MultiClusterRemediatorHelper
 */
class MultiClusterRemediatorHelperTest extends CirrusTestCase {

	public function testSendOptimized() {
		$wp = $this->createNoOpMock( WikiPage::class );

		$r1 = $this->createNoOpMock( Remediator::class );
		$r2 = $this->createNoOpMock( Remediator::class );

		$allClustersRemediator = $this->createMock( AllClustersQueueingRemediator::class );
		$allClustersRemediator->method( 'canSendOptimizedJob' )
			->with( [ 'c1', 'c2' ] )
			->willReturn( true );
		$allClustersRemediator->expects( $this->once() )
			->method( 'redirectInIndex' )
			->with( '42', $wp, 'content' );
		$b1 = new BufferedRemediator();
		$b2 = new BufferedRemediator();

		$helper = new MultiClusterRemediatorHelper( [ 'c1' => $r1, 'c2' => $r2 ], [ 'c1' => $b1, 'c2' => $b2 ],
			$allClustersRemediator );
		$b1->redirectInIndex( '42', $wp, 'content' );
		$b2->redirectInIndex( '42', $wp, 'content' );
		$helper->sendBatch();
	}

	public function testSendUnoptimized() {
		$wp = $this->createNoOpMock( WikiPage::class );

		$r1 = $this->createMock( Remediator::class );
		$r1->expects( $this->once() )
			->method( 'redirectInIndex' )
			->with( '42', $wp, 'general' );
		$r2 = $this->createMock( Remediator::class );
		$r2->expects( $this->once() )
			->method( 'pageNotInIndex' )
			->with( $wp );

		$allClustersRemediator = $this->createNoOpMock( AllClustersQueueingRemediator::class );

		$b1 = new BufferedRemediator();
		$b2 = new BufferedRemediator();

		$helper = new MultiClusterRemediatorHelper( [ 'c1' => $r1, 'c2' => $r2 ], [ 'c1' => $b1, 'c2' => $b2 ],
			$allClustersRemediator );
		$b1->redirectInIndex( '42', $wp, 'general' );
		$b2->pageNotInIndex( $wp );
		$helper->sendBatch();
	}

	public function testNotSimilarClusters() {
		$wp = $this->createNoOpMock( WikiPage::class );

		$r1 = $this->createMock( Remediator::class );
		$r1->expects( $this->once() )
			->method( 'redirectInIndex' )
			->with( '42', $wp, 'content' );

		$allClustersRemediator = $this->createNoOpMock( AllClustersQueueingRemediator::class,
			[ 'canSendOptimizedJob' ] );

		$allClustersRemediator->method( 'canSendOptimizedJob' )
			->with( [ 'c1' ] )
			->willReturn( false );

		$b1 = new BufferedRemediator();

		$helper = new MultiClusterRemediatorHelper( [ 'c1' => $r1 ], [ 'c1' => $b1 ], $allClustersRemediator );
		$b1->redirectInIndex( '42', $wp, 'content' );
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
