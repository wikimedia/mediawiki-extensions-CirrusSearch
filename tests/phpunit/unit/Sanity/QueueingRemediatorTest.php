<?php

namespace CirrusSearch\Sanity;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\Job\DeletePages;
use CirrusSearch\Job\LinksUpdate;
use JobQueueGroup;

/**
 * @covers \CirrusSearch\Sanity\QueueingRemediator
 */
class QueueingRemediatorTest extends CirrusTestCase {
	public function provideTestJobIsSent() {
		$title = \Title::makeTitle( NS_MAIN, 'Test' );
		$wp = $this->createMock( \WikiPage::class );
		$wp->method( 'getTitle' )->willReturn( $title );
		$wrongIndex = 'wrongType';
		$docId = '123';
		foreach ( [ null, 'c1' ] as $cluster ) {
			$linksUpdateJob = new LinksUpdate( $title, [
				'addedLinks' => [],
				'removedLinks' => [],
				'cluster' => $cluster
			] );

			$deletePageJob = new DeletePages( $title, [
				'docId' => $docId,
				'cluster' => $cluster,
			] );

			$wrongIndexDelete = new DeletePages( $title, [
				'indexSuffix' => $wrongIndex,
				'docId' => $docId,
				'cluster' => $cluster,
			] );

			$baseCaseName = $cluster === null ? 'for all clusters' : 'for some cluster';
			yield $baseCaseName . 'oldDocument' =>
				[ 'oldDocument', [ $wp ], [ $linksUpdateJob ], $cluster ];
			yield $baseCaseName . 'pageNotInIndex' =>
				[ 'pageNotInIndex', [ $wp ], [ $linksUpdateJob ], $cluster ];
			yield $baseCaseName . 'redirectInIndex' =>
				[ 'redirectInIndex', [ $wp ], [ $linksUpdateJob ], $cluster ];
			yield $baseCaseName . 'oldVersionInIndex' =>
				[ 'oldVersionInIndex', [ $docId, $wp, $wrongIndex ], [ $linksUpdateJob ], $cluster ];
			yield $baseCaseName . 'pageInWrongIndex' =>
				[ 'pageInWrongIndex', [ $docId, $wp, $wrongIndex ], [ $wrongIndexDelete, $linksUpdateJob ], $cluster ];
			yield $baseCaseName . 'ghostPageInIndex' =>
				[ 'ghostPageInIndex', [ $docId, $title, $wrongIndex ], [ $deletePageJob ], $cluster ];
		}
	}

	/**
	 * @dataProvider provideTestJobIsSent()
	 * @param string $methodCall
	 * @param array $methodParams
	 * @param array $jobs
	 * @param string|null $cluster
	 */
	public function testJobIsSent( $methodCall, array $methodParams, array $jobs, $cluster ) {
		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		call_user_func_array(
			[ $jobQueueGroup->expects( $this->exactly( count( $jobs ) ) )->method( 'push' ), 'withConsecutive' ],
			array_map(
				function ( $j ) {
					return [ $this->equalTo( $j ) ];
				},
				$jobs
			)
		);
		$remediator = new QueueingRemediator( $cluster, $jobQueueGroup );
		call_user_func_array( [ $remediator, $methodCall ], $methodParams );
	}
}
