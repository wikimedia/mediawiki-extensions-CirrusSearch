<?php

namespace CirrusSearch;

use CirrusSearch\Job\DeletePages;
use CirrusSearch\Job\LinksUpdate as CirrusLinksUpdate;
use MediaWiki\Deferred\LinksUpdate\LinksUpdate;
use MediaWiki\Revision\RevisionRecord;
use Wikimedia\Assert\Assert;

class ChangeListenerTest extends CirrusTestCase {
	/**
	 * @covers \CirrusSearch\ChangeListener::prepareTitlesForLinksUpdate()
	 */
	public function testPrepareTitlesForLinksUpdate() {
		$changeListener = new ChangeListener(
			$this->createMock( \JobQueueGroup::class ),
			$this->newHashSearchConfig( [] ),
			$this->createMock( \LoadBalancer::class )
		);
		$titles = [ \Title::makeTitle( NS_MAIN, 'Title1' ), \Title::makeTitle( NS_MAIN, 'Title2' ) ];
		$this->assertEqualsCanonicalizing(
			[ 'Title1', 'Title2' ],
			$changeListener->prepareTitlesForLinksUpdate( $titles, 2 ),
			'All titles must be returned'
		);
		$this->assertCount( 1, $changeListener->prepareTitlesForLinksUpdate( $titles, 1 ) );
		$titles = [ \Title::makeTitle( NS_MAIN, 'Title1' ), \Title::makeTitle( NS_MAIN, 'Title' . chr( 130 ) ) ];
		$this->assertEqualsCanonicalizing( [ 'Title1', 'Title' . chr( 130 ) ],
			$changeListener->prepareTitlesForLinksUpdate( $titles, 2 ),
			'Bad UTF8 links are kept by default'
		);
		$this->assertEquals( [ 'Title1' ], $changeListener->prepareTitlesForLinksUpdate( $titles, 2, true ),
			'Bad UTF8 links can be filtered' );
	}

	/**
	 * @covers       \CirrusSearch\ChangeListener::onLinksUpdateComplete
	 * @dataProvider provideTestOnLinksUpdateComplete
	 * @param int $now
	 * @param bool $recursive
	 * @param int|null $revTimestamp
	 * @param array $jobParams
	 * @return void
	 */
	public function testOnLinksUpdateComplete( int $now, bool $recursive, ?int $revTimestamp, array $jobParams ) {
		Assert::precondition( ( $revTimestamp !== null ) === $recursive, '$revTimestamp must be set if recursive is true' );
		$config = [
			'CirrusSearchLinkedArticlesToUpdate' => 10,
			'CirrusSearchUnlinkedArticlesToUpdate' => 10,
			'CirrusSearchEnableIncomingLinkCounting' => false,
			'CirrusSearchUpdateDelay' => [
				'prioritized' => 0,
				'default' => 0,
			]
		];
		\MWTimestamp::setFakeTime( $now );

		$title = $this->createMock( \Title::class );
		$title->method( 'getPrefixedDBkey' )->willReturn( 'My_Title' );

		$jobqueue = $this->createMock( \JobQueueGroup::class );
		$jobqueue->expects( $this->once() )->method( 'lazyPush' )->with( new CirrusLinksUpdate( $title, $jobParams ) );

		$linksUpdate = $this->createMock( LinksUpdate::class );
		$linksUpdate->method( 'getTitle' )->willReturn( $title );
		if ( $revTimestamp !== null ) {
			$revision = $this->createMock( RevisionRecord::class );
			$revision->method( 'getTimestamp' )->willReturn( \MWTimestamp::convert( TS_MW, $revTimestamp ) );
			$linksUpdate->method( 'getRevisionRecord' )->willReturn( $revision );
		}
		$linksUpdate->method( 'isRecursive' )->willReturn( $recursive );

		$listener = new ChangeListener( $jobqueue, $this->newHashSearchConfig( $config ), $this->createMock( \LoadBalancer::class ) );

		$listener->onLinksUpdateComplete( $linksUpdate, null );
	}

	public function provideTestOnLinksUpdateComplete(): array {
		return [
			'simple page refresh' => [
				123,
				false,
				null,
				[
					"update_kind" => "page_refresh",
					"root_event_time" => 123,
					"prioritize" => false
				]
			],
			'simple rev update' => [
				123,
				true,
				122,
				[
					"update_kind" => "page_change",
					"root_event_time" => 122,
					"prioritize" => true
				]
			]
		];
	}

	/**
	 * @covers \CirrusSearch\ChangeListener::onUploadComplete
	 */
	public function testOnFileUploadComplete() {
		$now = 123;
		\MWTimestamp::setFakeTime( $now );
		$title = $this->createMock( \Title::class );
		$title->method( 'getPrefixedDBkey' )->willReturn( 'My_Title' );
		$title->method( 'exists' )->willReturn( true );

		$uploadBase = $this->createMock( \UploadBase::class );
		$uploadBase->method( 'getTitle' )->willReturn( $title );

		$jobqueue = $this->createMock( \JobQueueGroup::class );
		$expectedJobParam = [
			"update_kind" => "page_change",
			"root_event_time" => $now,
			"prioritize" => true,
		];
		$jobqueue->expects( $this->once() )->method( 'push' )->with( new CirrusLinksUpdate( $title,  $expectedJobParam ) );

		$listener = new ChangeListener( $jobqueue, $this->newHashSearchConfig(), $this->createMock( \LoadBalancer::class ) );
		$listener->onUploadComplete( $uploadBase );
	}

	/**
	 * @covers \CirrusSearch\ChangeListener::onArticleDeleteComplete
	 */
	public function testOnArticleDeleteComplete() {
		$now = 321;
		$pageId = 123;
		$page = $this->createMock( \WikiPage::class );
		$title = $this->createMock( \Title::class );
		$page->method( 'getTitle' )->willReturn( $title );
		$logEntry = $this->createMock( \LogEntry::class );
		$logEntry->method( 'getTimestamp' )->willReturn( \MWTimestamp::convert( TS_MW, $now ) );
		$jobqueue = $this->createMock( \JobQueueGroup::class );

		$expectedJobParam = [
			"docId" => (string)$pageId,
			"update_kind" => "page_change",
			"root_event_time" => $now,
		];
		$jobqueue->expects( $this->once() )->method( 'push' )->with( new DeletePages( $title, $expectedJobParam ) );
		$listener = new ChangeListener( $jobqueue, $this->newHashSearchConfig(), $this->createMock( \LoadBalancer::class ) );
		$listener->onArticleDeleteComplete( $page, $this->createMock( \User::class ), "a reason", $pageId, null, $logEntry, 2 );
	}
}
