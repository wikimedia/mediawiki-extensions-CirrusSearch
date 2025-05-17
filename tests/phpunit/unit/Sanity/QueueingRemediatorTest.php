<?php

namespace CirrusSearch\Sanity;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\Job\DeletePages;
use CirrusSearch\Job\LinksUpdate;
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
		$jobQueueGroup = $this->createMock( JobQueueGroup::class );
		$jobQueueGroup->expects( $this->exactly( count( $jobs ) ) )
			->method( 'push' )
			->willReturnCallback( function ( $j ) use ( &$jobs ): void {
				$this->assertEquals( array_shift( $jobs ), $j );
			} );
		$remediator = new QueueingRemediator( $cluster, $jobQueueGroup );
		$remediator->$methodCall( ...$methodParams );
	}
}
