<?php

namespace CirrusSearch\Sanity;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\Job\DeletePages;
use CirrusSearch\Job\LinksUpdate;
use CirrusSearch\Job\UpdateRedirectDocument;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Page\WikiPage;
use MediaWiki\Title\Title;
use MediaWiki\Utils\MWTimestamp;

/**
 * @covers \CirrusSearch\Sanity\QueueingRemediator
 */
class QueueingRemediatorTest extends CirrusTestCase {
	private const NOW = 123;

	public static function provideTestJobIsSent() {
		$title = Title::makeTitle( NS_MAIN, 'Test' );
		$wrongIndex = 'wrongType';
		$docId = '123';
		foreach ( [ null, 'c1' ] as $cluster ) {
			$linksUpdateJob = new LinksUpdate( $title, [
				'cluster' => $cluster,
				'update_kind' => 'saneitizer',
				'root_event_time' => self::NOW,
				'prioritize' => false
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

			$baseCaseName = $cluster === null ? 'for all clusters ' : 'for some cluster ';
			yield $baseCaseName . 'oldDocument' =>
				[ 'oldDocument', [ 'wikiPage' ], [ $linksUpdateJob ], $cluster ];
			yield $baseCaseName . 'pageNotInIndex' =>
				[ 'pageNotInIndex', [ 'wikiPage' ], [ $linksUpdateJob ], $cluster ];
			yield $baseCaseName . 'redirectInIndex' =>
				[ 'redirectInIndex', [ $docId, 'wikiPage', $wrongIndex ], [ $wrongIndexDelete, $linksUpdateJob ], $cluster ];
			yield $baseCaseName . 'oldVersionInIndex' =>
				[ 'oldVersionInIndex', [ $docId, 'wikiPage', $wrongIndex ], [ $linksUpdateJob ], $cluster ];
			yield $baseCaseName . 'pageInWrongIndex' =>
				[ 'pageInWrongIndex', [ $docId, 'wikiPage', $wrongIndex ], [ $wrongIndexDelete, $linksUpdateJob ], $cluster ];
			yield $baseCaseName . 'ghostPageInIndex' =>
				[ 'ghostPageInIndex', [ $docId, $title, $wrongIndex ], [ $deletePageJob ], $cluster ];
		}
	}

	/**
	 * @dataProvider provideTestJobIsSent
	 * @param string $methodCall
	 * @param array $methodParams
	 * @param array $jobs
	 * @param string|null $cluster
	 */
	public function testJobIsSent( $methodCall, array $methodParams, array $jobs, $cluster ) {
		foreach ( $methodParams as &$param ) {
			if ( $param === 'wikiPage' ) {
				$wp = $this->createMock( WikiPage::class );
				$wp->method( 'getTitle' )->willReturn( Title::makeTitle( NS_MAIN, 'Test' ) );
				$param = $wp;
			}
		}

		MWTimestamp::setFakeTime( self::NOW );
		$jobQueueGroup = $this->newJobQueueGroupExpecting( $jobs );
		$remediator = new QueueingRemediator( $cluster, $jobQueueGroup );
		$remediator->$methodCall( ...$methodParams );
	}

	/**
	 * Builds a JobQueueGroup mock that expects exactly the given jobs to be
	 * pushed, in order, comparing each push against the next expected job by
	 * deduplication info.
	 *
	 * @param \CirrusSearch\Job\CirrusTitleJob[] $jobs
	 */
	private function newJobQueueGroupExpecting( array $jobs ): JobQueueGroup {
		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$jobQueueGroup->expects( $this->exactly( count( $jobs ) ) )
			->method( 'push' )
			->willReturnCallback( function ( $j ) use ( &$jobs ): void {
				$expected = array_shift( $jobs );
				// Ignore requestId: it is set by the Job constructor from a
				// process-level singleton, and may differ between the data
				// provider (which runs during suite construction) and the
				// test method.
				$this->assertEquals(
					$expected->getDeduplicationInfo(),
					$j->getDeduplicationInfo()
				);
			} );
		return $jobQueueGroup;
	}

	public static function provideRedirectHandling() {
		$title = Title::makeTitle( NS_MAIN, 'Test' );
		$docId = '123';
		$indexSuffix = 'content';

		foreach ( [ null, 'c1' ] as $cluster ) {
			$linksUpdate = new LinksUpdate( $title, [
				'cluster' => $cluster,
				'update_kind' => 'saneitizer',
				'root_event_time' => self::NOW,
				'prioritize' => false,
			] );
			$redirectUpdate = new UpdateRedirectDocument( $title, [
				'cluster' => $cluster,
				'update_kind' => 'saneitizer',
				'root_event_time' => self::NOW,
			] );

			$suffix = $cluster === null ? 'all clusters' : "cluster $cluster";
			// Every remediation that traces through pushLinksUpdateJob must route to
			// UpdateRedirectDocument when the Checker classified the page as a redirect and
			// redirect documents are built (with the cluster threaded through), and stay on
			// LinksUpdate otherwise.
			yield "pageNotInIndex, build -> UpdateRedirectDocument ($suffix)" =>
				[ true, $cluster, 'pageNotInIndex', [ 'redirectPage' ], $redirectUpdate ];
			yield "pageNotInIndex, no build -> LinksUpdate ($suffix)" =>
				[ false, $cluster, 'pageNotInIndex', [ 'redirectPage' ], $linksUpdate ];
			yield "oldVersionInIndex, build -> UpdateRedirectDocument ($suffix)" =>
				[ true, $cluster, 'oldVersionInIndex', [ $docId, 'redirectPage', $indexSuffix ], $redirectUpdate ];
			yield "oldVersionInIndex, no build -> LinksUpdate ($suffix)" =>
				[ false, $cluster, 'oldVersionInIndex', [ $docId, 'redirectPage', $indexSuffix ], $linksUpdate ];
		}
	}

	/**
	 * @dataProvider provideRedirectHandling
	 * @param bool $build
	 * @param string|null $cluster
	 * @param string $methodCall
	 * @param array $methodParams
	 * @param \CirrusSearch\Job\CirrusTitleJob $expectedJob
	 */
	public function testRedirectHandling( bool $build, $cluster, string $methodCall, array $methodParams, $expectedJob ) {
		foreach ( $methodParams as &$param ) {
			if ( $param === 'redirectPage' ) {
				$wp = $this->createMock( WikiPage::class );
				$wp->method( 'getTitle' )->willReturn( Title::makeTitle( NS_MAIN, 'Test' ) );
				$wp->method( 'isRedirect' )->willReturn( true );
				$param = $wp;
			}
		}
		unset( $param );

		MWTimestamp::setFakeTime( self::NOW );
		$jobQueueGroup = $this->newJobQueueGroupExpecting( [ $expectedJob ] );
		$remediator = new QueueingRemediator( $cluster, $jobQueueGroup, $build );
		$remediator->$methodCall( ...$methodParams );
	}
}
