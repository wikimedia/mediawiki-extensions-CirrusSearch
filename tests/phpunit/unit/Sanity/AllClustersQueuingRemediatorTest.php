<?php

namespace CirrusSearch\Sanity;

use CirrusSearch\Assignment\ClusterAssignment;
use CirrusSearch\CirrusTestCase;
use CirrusSearch\Job\DeletePages;
use CirrusSearch\Job\LinksUpdate;
use JobQueueGroup;
use MediaWiki\Title\Title;
use MediaWiki\Utils\MWTimestamp;

/**
 * @covers \CirrusSearch\Sanity\AllClustersQueueingRemediator
 */
class AllClustersQueuingRemediatorTest extends CirrusTestCase {

	public function testCanSendOptimizedJob() {
		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$clusters = [ 'one', 'two' ];
		$clusterAssigment = $this->createMock( ClusterAssignment::class );
		$clusterAssigment->expects( $this->once() )
			->method( 'getWritableClusters' )
			->willReturn( $clusters );
		$allClustersRemediator = new AllClustersQueueingRemediator( $clusterAssigment, $jobQueueGroup );
		$this->assertTrue( $allClustersRemediator->canSendOptimizedJob( $clusters ) );
		$this->assertTrue( $allClustersRemediator->canSendOptimizedJob( [ 'one', 'two' ] ) );
		$this->assertTrue( $allClustersRemediator->canSendOptimizedJob( [ 'two', 'one' ] ) );
		$this->assertFalse( $allClustersRemediator->canSendOptimizedJob( [ 'one' ] ) );
		$this->assertFalse( $allClustersRemediator->canSendOptimizedJob( [ 'one', 'two', 'three' ] ) );
		$this->assertFalse( $allClustersRemediator->canSendOptimizedJob( [] ) );
	}

	public function testDelegation() {
		$now = 123;
		MWTimestamp::setFakeTime( $now );
		$title = Title::makeTitle( NS_MAIN, 'Test' );
		$wp = $this->createMock( \WikiPage::class );
		$wp->method( 'getTitle' )->willReturn( $title );
		$wrongIndex = 'wrongType';
		$docId = '123';
		$baseParams = [
			'update_kind' => 'saneitizer',
			'root_event_time' => $now,
			'prioritize' => false
		];
		$linksUpdateJob = new LinksUpdate( $title, [
			'cluster' => null,
		] + $baseParams );

		$deletePageJob = new DeletePages( $title, [
			'docId' => $docId,
			'cluster' => null,
		] );

		$wrongIndexDelete = new DeletePages( $title, [
			'indexSuffix' => $wrongIndex,
			'docId' => $docId,
			'cluster' => null,
		] );
		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$clusterAssigment = $this->createMock( ClusterAssignment::class );
		$clusterAssigment->expects( $this->once() )
			->method( 'getWritableClusters' )
			->willReturn( [ 'one', 'two' ] );
		$expectedJobs = [
			$linksUpdateJob, // oldDocument
			$linksUpdateJob, // pageNotIndex
			$linksUpdateJob, // redirectInIndex
			$linksUpdateJob, // oldVersionInIndex
			$wrongIndexDelete, // pageInWrongIndex step1
			$linksUpdateJob, // pageInWrongIndex step2
			$deletePageJob // ghostPageInIndex
		];
		$jobQueueGroup->expects( $this->exactly( count( $expectedJobs ) ) )
			->method( 'push' )
			->willReturnCallback( function ( $jobs ) use ( &$expectedJobs ): void {
				$this->assertEquals( array_shift( $expectedJobs ), $jobs );
			} );

		$allClustersRemediator = new AllClustersQueueingRemediator( $clusterAssigment, $jobQueueGroup );
		$allClustersRemediator->oldDocument( $wp );
		$allClustersRemediator->pageNotInIndex( $wp );
		$allClustersRemediator->redirectInIndex( $wp );
		$allClustersRemediator->oldVersionInIndex( $docId, $wp, $wrongIndex );
		$allClustersRemediator->pageInWrongIndex( $docId, $wp, $wrongIndex );
		$allClustersRemediator->ghostPageInIndex( $docId, $title );
	}
}
