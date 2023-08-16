<?php

namespace CirrusSearch;

use CirrusSearch\Job\DeletePages;
use CirrusSearch\Job\LinksUpdate as CirrusLinksUpdate;
use Liuggio\StatsdClient\Factory\StatsdDataFactoryInterface;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Deferred\DeferredUpdatesManager;
use MediaWiki\Deferred\LinksUpdate\LinksUpdate;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Page\RedirectLookup;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\MutableRevisionRecord;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentityValue;
use Psr\Log\LoggerInterface;
use Wikimedia\Rdbms\ILBFactory;

class ChangeListenerTest extends CirrusTestCase {
	private DeferredUpdatesManager $deferredUpdateManager;

	protected function setUp(): void {
		parent::setUp();

		$lbFactory = $this->createMock( ILBFactory::class );
		$lbFactory->method( 'hasTransactionRound' )->willReturn( false );
		$lbFactory->method( 'getEmptyTransactionTicket' )->willReturn( 'a ticket' );
		$this->deferredUpdateManager = new DeferredUpdatesManager(
			new ServiceOptions( DeferredUpdatesManager::CONSTRUCTOR_OPTIONS, [ 'CommandLineMode' => false ] ),
			$this->createMock( LoggerInterface::class ), $lbFactory,
			$this->createMock( StatsdDataFactoryInterface::class ),
			$this->createMock( JobQueueGroupFactory::class )
		);
	}

	protected function tearDown(): void {
		$this->deferredUpdateManager->clearPendingUpdates();
	}

	/**
	 * @covers \CirrusSearch\ChangeListener::prepareTitlesForLinksUpdate()
	 */
	public function testPrepareTitlesForLinksUpdate() {
		$changeListener = new ChangeListener(
			$this->createMock( \JobQueueGroup::class ),
			$this->newHashSearchConfig( [] ),
			$this->createMock( \LoadBalancer::class ),
			$this->createMock( RedirectLookup::class ),
			$this->deferredUpdateManager
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
	 * @param callable $callable
	 * @param int $pageId
	 * @param int|null $revTimestamp
	 * @param array $jobParams
	 */
	public function testOnLinksUpdateComplete( int $now, callable $callable, int $pageId, ?int $revTimestamp, array $jobParams ) {
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
		$linksUpdate->method( 'getPageId' )->willReturn( $pageId );

		$listener = new ChangeListener( $jobqueue, $this->newHashSearchConfig( $config ),
			$this->createMock( \LoadBalancer::class ), $this->createMock( RedirectLookup::class ),
			$this->deferredUpdateManager );

		$page = $this->createMock( \WikiPage::class );
		$page->method( 'getId' )->willReturn( $pageId );
		$listener->onLinksUpdateComplete( $linksUpdate, null );
		$callable( $listener, $page );
		$this->deferredUpdateManager->doUpdates();
	}

	public static function provideTestOnLinksUpdateComplete(): array {
		return [
			'simple page edit' => [
				123,
				static function ( ChangeListener $listener, \WikiPage $page ) {
					$editResult = new EditResult( false, 1, null,
					null, null, false, false, [] );
					$listener->onPageSaveComplete( $page, new UserIdentityValue( 1, '' ),
					'', 0, new MutableRevisionRecord( $page ), $editResult );
				},
				1,
				124,
				[
					"update_kind" => "page_change",
					"root_event_time" => 124,
					"prioritize" => true
				]
			],
			'' => [
				123,
				static function ( ChangeListener $listener, \WikiPage $page ) {
				},
				1,
				null,
				[
					"update_kind" => "page_refresh",
					"root_event_time" => 123,
					"prioritize" => false
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
		$jobqueue->expects( $this->once() )->method( 'lazyPush' )->with( new CirrusLinksUpdate( $title,  $expectedJobParam ) );

		$listener = new ChangeListener( $jobqueue, $this->newHashSearchConfig(),
			$this->createMock( \LoadBalancer::class ), $this->createMock( RedirectLookup::class ),
			$this->deferredUpdateManager );
		$listener->onUploadComplete( $uploadBase );
	}

	/**
	 * @covers \CirrusSearch\ChangeListener::onPageDeleteComplete
	 */
	public function testOnPageDeleteComplete() {
		$now = 321;
		$pageId = 123;
		$page = $this->createMock( \WikiPage::class );
		$title = $this->createMock( \Title::class );
		$page->method( 'getTitle' )->willReturn( $title );
		$logEntry = $this->createMock( \ManualLogEntry::class );
		$logEntry->method( 'getTimestamp' )->willReturn( \MWTimestamp::convert( TS_MW, $now ) );
		$jobqueue = $this->createMock( \JobQueueGroup::class );

		$expectedJobParam = [
			"docId" => (string)$pageId,
			"update_kind" => "page_change",
			"root_event_time" => $now,
		];
		$jobqueue->expects( $this->once() )->method( 'lazyPush' )->with( new DeletePages( $title, $expectedJobParam ) );
		$listener = new ChangeListener( $jobqueue, $this->newHashSearchConfig(),
			$this->createMock( \LoadBalancer::class ), $this->createMock( RedirectLookup::class ),
			$this->deferredUpdateManager );
		$listener->onPageDeleteComplete( $page, $this->createMock( Authority::class ),
			"a reason", $pageId, $this->createMock( RevisionRecord::class ), $logEntry, 2 );
	}

	/**
	 * @covers \CirrusSearch\ChangeListener::onArticleRevisionVisibilitySet
	 * @covers \CirrusSearch\Job\LinksUpdate::newPastRevisionVisibilityChange
	 */
	public function testOnArticleRevisionVisibilitySet() {
		$now = 321;
		\MWTimestamp::setFakeTime( $now );
		$title = $this->createMock( \Title::class );
		$jobqueue = $this->createMock( \JobQueueGroup::class );
		$expectedJobParam = [
			"update_kind" => "visibility_change",
			"root_event_time" => $now,
			"prioritize" => true
		];
		$jobqueue->expects( $this->once() )->method( 'lazyPush' )->with( new CirrusLinksUpdate( $title, $expectedJobParam ) );
		$listener = new ChangeListener( $jobqueue, $this->newHashSearchConfig(),
			$this->createMock( \LoadBalancer::class ), $this->createMock( RedirectLookup::class ),
			$this->deferredUpdateManager );
		$listener->onArticleRevisionVisibilitySet( $title, [], [] );
	}

	/**
	 * @covers \CirrusSearch\ChangeListener::onPageDelete
	 */
	public function testOnPageDelete() {
		$jobqueue = $this->createMock( \JobQueueGroup::class );
		$redirectLookup = $this->createMock( RedirectLookup::class );
		$redirect = new PageIdentityValue( 123, 0, 'Deleted_Redirect', false );
		$page = new PageIdentityValue( 124, 0, 'A_Page', false );
		$deleter = $this->createMock( Authority::class );

		$target = Title::makeTitle( 0, 'Redir_Target' );

		$redirectLookup->expects( $this->exactly( 2 ) )
			->method( 'getRedirectTarget' )
			->withConsecutive( [ $redirect ], [ $page ] )
			->willReturnOnConsecutiveCalls( $target, null );

		$jobqueue->expects( $this->once() )->method( 'lazyPush' )->with( new Job\LinksUpdate( $target, [] ) );

		$listener = new ChangeListener( $jobqueue, $this->newHashSearchConfig(),
			$this->createMock( \LoadBalancer::class ), $redirectLookup, $this->deferredUpdateManager );
		$listener->onPageDelete( $redirect, $deleter, 'unused', new \StatusValue(), false );
		$listener->onPageDelete( $page, $deleter, 'unused', new \StatusValue(), false );
	}

	/**
	 * @covers \CirrusSearch\ChangeListener::onPageUndeleteComplete
	 */
	public function testOnPageUndeleteComplete() {
		$jobqueue = $this->createMock( \JobQueueGroup::class );
		$page = new PageIdentityValue( 124, 0, 'A_Restored_Page', false );
		$title = Title::castFromPageIdentity( $page );
		$restoredPageIds = [ 123, 124 ];
		$listener = new ChangeListener( $jobqueue, $this->newHashSearchConfig( [ 'CirrusSearchIndexDeletes' => true ] ),
			$this->createMock( \LoadBalancer::class ), $this->createMock( RedirectLookup::class ),
			$this->deferredUpdateManager );
		$jobqueue->expects( $this->once() )
			->method( 'lazyPush' )
			->with( new Job\DeleteArchive( $title, [ 'docIds' => $restoredPageIds ] ) );
		$listener->onPageUndeleteComplete( $page, $this->createMock( Authority::class ),
			'a reson',  $this->createMock( RevisionRecord::class ),
			$this->createMock( \ManualLogEntry::class ), 2, true, $restoredPageIds );
	}
}
