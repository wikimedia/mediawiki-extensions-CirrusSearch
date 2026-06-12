<?php

namespace CirrusSearch;

use CirrusSearch\Job\CirrusTitleJob;
use CirrusSearch\Job\DeletePages;
use CirrusSearch\Job\LinksUpdate as CirrusLinksUpdate;
use CirrusSearch\Job\UpdateRedirectDocument;
use MediaWiki\Content\Content;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Deferred\LinksUpdate\LinksUpdate;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Page\RedirectLookup;
use MediaWiki\Page\WikiPage;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\MutableRevisionRecord;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Status\Status;
use MediaWiki\Storage\EditResult;
use MediaWiki\Title\Title;
use MediaWiki\Upload\UploadBase;
use MediaWiki\User\User;
use MediaWiki\User\UserIdentityValue;
use MediaWiki\Utils\MWTimestamp;
use Wikimedia\Rdbms\IConnectionProvider;

class ChangeListenerTest extends CirrusIntegrationTestCase {

	public static function provideConfig(): array {
		return [
			'default cluster setup' => [
				[
					'CirrusSearchClusters' => [
						'mycluster' => [ '127.0.0.1' ],
					],
					'CirrusSearchDefaultCluster' => 'mycluster',
					'CirrusSearchWriteClusters' => null,
					'CirrusSearchReplicaGroup' => 'default',
				],
				true
			],
			'writable cluster set' => [
				[
					'CirrusSearchClusters' => [
						'mycluster' => [ '127.0.0.1' ],
					],
					'CirrusSearchDefaultCluster' => 'mycluster',
					'CirrusSearchWriteClusters' => [
						'default' => [],
						UpdateGroup::PAGE => [ 'mycluster' ]
					],
					'CirrusSearchReplicaGroup' => 'default',
				],
				true
			],
			'no writable cluster set' => [
				[
					'CirrusSearchClusters' => [
						'mycluster' => [ '127.0.0.1' ],
					],
					'CirrusSearchDefaultCluster' => 'mycluster',
					'CirrusSearchWriteClusters' => [
						'default' => [],
					],
					'CirrusSearchReplicaGroup' => 'default',
				],
				false
			],
		];
	}

	/**
	 * @covers \CirrusSearch\ChangeListener::preparePageReferencesForLinksUpdate()
	 */
	public function testPreparePageReferencesForLinksUpdate() {
		$changeListener = new ChangeListener(
			$this->createNoOpMock( JobQueueGroup::class ),
			$this->newHashSearchConfig(),
			$this->createNoOpMock( IConnectionProvider::class ),
			$this->createNoOpMock( RedirectLookup::class )
		);
		$titles = [ Title::makeTitle( NS_MAIN, 'Title1' ), Title::makeTitle( NS_MAIN, 'Title2' ) ];
		$this->assertEqualsCanonicalizing(
			[ 'Title1', 'Title2' ],
			$changeListener->preparePageReferencesForLinksUpdate( $titles, 2 ),
			'All titles must be returned'
		);
		$this->assertCount( 1, $changeListener->preparePageReferencesForLinksUpdate( $titles, 1 ) );
		$titles = [ Title::makeTitle( NS_MAIN, 'Title1' ), Title::makeTitle( NS_MAIN, 'Title' . chr( 130 ) ) ];
		$this->assertEqualsCanonicalizing( [ 'Title1', 'Title' . chr( 130 ) ],
			$changeListener->preparePageReferencesForLinksUpdate( $titles, 2 ),
			'Bad UTF8 links are kept by default'
		);
		$this->assertEquals( [ 'Title1' ], $changeListener->preparePageReferencesForLinksUpdate( $titles, 2, true ),
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
	public function testOnLinksUpdateComplete(
		int $now,
		callable $callable,
		int $pageId,
		?int $revTimestamp,
		array $jobParams,
		array $config,
		bool $withJob
	) {
		$config = [
			'CirrusSearchLinkedArticlesToUpdate' => 10,
			'CirrusSearchUnlinkedArticlesToUpdate' => 10,
			'CirrusSearchEnableIncomingLinkCounting' => false,
			'CirrusSearchUpdateDelay' => [
				'prioritized' => 0,
				'default' => 0,
			]
		] + $config;
		$cleanup = DeferredUpdates::preventOpportunisticUpdates();
		MWTimestamp::setFakeTime( $now );

		$title = $this->createMock( Title::class );
		$title->method( 'getPrefixedDBkey' )->willReturn( 'My_Title' );

		$jobqueue = $this->createMock( JobQueueGroup::class );
		$jobqueue->expects( $this->exactly( $withJob ? 1 : 0 ) )
			->method( 'lazyPush' )
			->with( new CirrusLinksUpdate( $title, $jobParams ) );

		$linksUpdate = $this->createMock( LinksUpdate::class );
		$linksUpdate->method( 'getTitle' )->willReturn( $title );
		if ( $revTimestamp !== null ) {
			$revision = $this->createMock( RevisionRecord::class );
			$revision->method( 'getTimestamp' )->willReturn( MWTimestamp::convert( TS_MW, $revTimestamp ) );
			$linksUpdate->method( 'getRevisionRecord' )->willReturn( $revision );
		}
		$linksUpdate->method( 'getPageId' )->willReturn( $pageId );

		$listener = new ChangeListener( $jobqueue, $this->newHashSearchConfig( $config ),
			$this->createNoOpMock( IConnectionProvider::class ),
			$this->createNoOpMock( RedirectLookup::class )
		);

		$page = $this->createMock( WikiPage::class );
		$page->method( 'getId' )->willReturn( $pageId );
		$listener->onLinksUpdateComplete( $linksUpdate, null );
		$callable( $listener, $page );
		DeferredUpdates::doUpdates();
	}

	public static function provideTestOnLinksUpdateComplete(): array {
		$cases = [];
		foreach ( self::provideConfig() as $case => $setup ) {
			$cases["simple page edit with $case"] = [
				123,
				static function ( ChangeListener $listener, WikiPage $page ) {
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
				],
				$setup[0],
				$setup[1]
			];
			$cases["simple page page refresh with $case"] = [
				123,
				static function ( ChangeListener $listener, WikiPage $page ) {
				},
				1,
				null,
				[
					"update_kind" => "page_refresh",
					"root_event_time" => 123,
					"prioritize" => false
				],
				$setup[0],
				$setup[1]
			];
		}
		return $cases;
	}

	private function redirectDocConfig( bool $build ): array {
		return [
			'CirrusSearchLinkedArticlesToUpdate' => 10,
			'CirrusSearchUnlinkedArticlesToUpdate' => 10,
			'CirrusSearchEnableIncomingLinkCounting' => false,
			'CirrusSearchUpdateDelay' => [ 'prioritized' => 0, 'default' => 0 ],
			'CirrusSearchRedirectDocuments' => [ 'build' => $build, 'use' => false ],
			'CirrusSearchClusters' => [ 'mycluster' => [ '127.0.0.1' ] ],
			'CirrusSearchDefaultCluster' => 'mycluster',
			'CirrusSearchWriteClusters' => null,
			'CirrusSearchReplicaGroup' => 'default',
		];
	}

	private function mockRedirectLinksUpdate( Title $title, bool $isRedirect ): LinksUpdate {
		$content = $this->createMock( Content::class );
		$content->method( 'isRedirect' )->willReturn( $isRedirect );
		$revision = $this->createMock( RevisionRecord::class );
		$revision->method( 'getTimestamp' )->willReturn( MWTimestamp::convert( TS_MW, 124 ) );
		$revision->method( 'getContent' )->with( SlotRecord::MAIN )->willReturn( $content );

		$linksUpdate = $this->createMock( LinksUpdate::class );
		$linksUpdate->method( 'getTitle' )->willReturn( $title );
		$linksUpdate->method( 'getRevisionRecord' )->willReturn( $revision );
		$linksUpdate->method( 'getPageId' )->willReturn( 1 );
		return $linksUpdate;
	}

	public static function provideRedirectDocumentCases(): array {
		return [
			'page change, redirect, build on -> both jobs' => [
				true, true, true,
				[ 'cirrusSearchLinksUpdatePrioritized', 'cirrusSearchUpdateRedirectDocument' ],
			],
			'page refresh, redirect, build on -> both jobs' => [
				true, true, false,
				[ 'cirrusSearchLinksUpdate', 'cirrusSearchUpdateRedirectDocument' ],
			],
			'page change, redirect, build off -> only LinksUpdate' => [
				false, true, true,
				[ 'cirrusSearchLinksUpdatePrioritized' ],
			],
			'page change, not a redirect, build on -> only LinksUpdate' => [
				true, false, true,
				[ 'cirrusSearchLinksUpdatePrioritized' ],
			],
		];
	}

	/**
	 * @covers \CirrusSearch\ChangeListener::onLinksUpdateComplete
	 * @dataProvider provideRedirectDocumentCases
	 */
	public function testOnLinksUpdateCompleteRedirectDocument(
		bool $build, bool $isRedirect, bool $pageChange, array $expectedTypes
	) {
		$cleanup = DeferredUpdates::preventOpportunisticUpdates();
		MWTimestamp::setFakeTime( 123 );

		$title = $this->createMock( Title::class );
		$title->method( 'getPrefixedDBkey' )->willReturn( 'My_Title' );

		$pushed = [];
		$jobqueue = $this->createMock( JobQueueGroup::class );
		$jobqueue->method( 'lazyPush' )->willReturnCallback(
			static function ( $job ) use ( &$pushed ) {
				$pushed[] = $job;
			} );

		$linksUpdate = $this->mockRedirectLinksUpdate( $title, $isRedirect );
		$listener = new ChangeListener( $jobqueue, $this->newHashSearchConfig( $this->redirectDocConfig( $build ) ),
			$this->createNoOpMock( IConnectionProvider::class ),
			$this->createNoOpMock( RedirectLookup::class )
		);

		if ( $pageChange ) {
			$page = $this->createMock( WikiPage::class );
			$page->method( 'getId' )->willReturn( 1 );
			$editResult = new EditResult( false, 1, null, null, null, false, false, [] );
			$listener->onPageSaveComplete( $page, new UserIdentityValue( 1, '' ),
				'', 0, new MutableRevisionRecord( $page ), $editResult );
		}
		$listener->onLinksUpdateComplete( $linksUpdate, null );
		DeferredUpdates::doUpdates();

		$this->assertSame( $expectedTypes, array_map( static fn ( $job ) => $job->getType(), $pushed ) );
		foreach ( $pushed as $job ) {
			if ( $job instanceof UpdateRedirectDocument ) {
				$expectedKind = $pageChange ? CirrusTitleJob::PAGE_CHANGE : CirrusTitleJob::PAGE_REFRESH;
				$this->assertSame( $expectedKind, $job->getParams()[CirrusTitleJob::UPDATE_KIND] );
			}
		}
	}

	public static function provideMovingRedirectCases(): array {
		return [
			'moved-from title is a redirect -> only redirect job' => [
				true, [ 'cirrusSearchUpdateRedirectDocument' ],
			],
			'moved-from title is not a redirect -> no jobs' => [
				false, [],
			],
		];
	}

	/**
	 * @covers \CirrusSearch\ChangeListener::onLinksUpdateComplete
	 * @dataProvider provideMovingRedirectCases
	 */
	public function testOnLinksUpdateCompleteMovingRedirect( bool $isRedirect, array $expectedTypes ) {
		$cleanup = DeferredUpdates::preventOpportunisticUpdates();
		MWTimestamp::setFakeTime( 123 );

		$title = $this->createMock( Title::class );
		$title->method( 'getPrefixedDBkey' )->willReturn( 'My_Title' );

		$pushed = [];
		$jobqueue = $this->createMock( JobQueueGroup::class );
		$jobqueue->method( 'lazyPush' )->willReturnCallback(
			static function ( $job ) use ( &$pushed ) {
				$pushed[] = $job;
			} );

		$linksUpdate = $this->mockRedirectLinksUpdate( $title, $isRedirect );
		$listener = new ChangeListener( $jobqueue, $this->newHashSearchConfig( $this->redirectDocConfig( true ) ),
			$this->createNoOpMock( IConnectionProvider::class ),
			$this->createNoOpMock( RedirectLookup::class )
		);

		// Record the title as moving so the LinksUpdate for it would normally be skipped.
		$status = Status::newGood();
		$listener->onTitleMove( $title, $this->createMock( Title::class ),
			$this->createMock( User::class ), '', $status );
		$listener->onLinksUpdateComplete( $linksUpdate, null );
		DeferredUpdates::doUpdates();

		$this->assertSame( $expectedTypes, array_map( static fn ( $job ) => $job->getType(), $pushed ) );
		foreach ( $pushed as $job ) {
			if ( $job instanceof UpdateRedirectDocument ) {
				$this->assertSame( CirrusTitleJob::PAGE_CHANGE, $job->getParams()[CirrusTitleJob::UPDATE_KIND] );
			}
		}
	}

	/**
	 * @covers \CirrusSearch\ChangeListener::onUploadComplete
	 * @dataProvider provideConfig
	 */
	public function testOnFileUploadComplete( array $config, bool $expectedJob ) {
		$now = 123;
		MWTimestamp::setFakeTime( $now );
		$title = $this->createMock( Title::class );
		$title->method( 'getPrefixedDBkey' )->willReturn( 'My_Title' );
		$title->method( 'exists' )->willReturn( true );

		$uploadBase = $this->createMock( UploadBase::class );
		$uploadBase->method( 'getTitle' )->willReturn( $title );

		$jobqueue = $this->createMock( JobQueueGroup::class );
		$expectedJobParam = [
			"update_kind" => "page_change",
			"root_event_time" => $now,
			"prioritize" => true,
		];
		$jobqueue->expects( $this->exactly( $expectedJob ? 1 : 0 ) )
			->method( 'lazyPush' )
			->with( new CirrusLinksUpdate( $title, $expectedJobParam ) );

		$listener = new ChangeListener( $jobqueue, $this->newHashSearchConfig( $config ),
			$this->createNoOpMock( IConnectionProvider::class ),
			$this->createNoOpMock( RedirectLookup::class )
		);
		$listener->onUploadComplete( $uploadBase );
	}

	/**
	 * @covers \CirrusSearch\ChangeListener::onPageDeleteComplete
	 * @dataProvider provideConfig
	 */
	public function testOnPageDeleteComplete( array $config, bool $expectedJob ) {
		$now = 321;
		$pageId = 123;
		$page = $this->createMock( WikiPage::class );
		$title = $this->createMock( Title::class );
		$page->method( 'getTitle' )->willReturn( $title );
		$logEntry = $this->createMock( ManualLogEntry::class );
		$logEntry->method( 'getTimestamp' )->willReturn( MWTimestamp::convert( TS_MW, $now ) );
		$jobqueue = $this->createMock( JobQueueGroup::class );

		$expectedJobParam = [
			"docId" => (string)$pageId,
			"update_kind" => "page_change",
			"root_event_time" => $now,
		];
		$jobqueue->expects( $this->exactly( $expectedJob ? 1 : 0 ) )
			->method( 'lazyPush' )
			->with( new DeletePages( $title, $expectedJobParam ) );
		$listener = new ChangeListener( $jobqueue, $this->newHashSearchConfig( $config ),
			$this->createNoOpMock( IConnectionProvider::class ),
			$this->createNoOpMock( RedirectLookup::class )
		);
		$listener->onPageDeleteComplete( $page,
			$this->createNoOpMock( Authority::class ),
			"a reason", $pageId,
			$this->createNoOpMock( RevisionRecord::class ), $logEntry, 2 );
	}

	/**
	 * @covers \CirrusSearch\ChangeListener::onArticleRevisionVisibilitySet
	 * @covers \CirrusSearch\Job\LinksUpdate::newPastRevisionVisibilityChange
	 * @dataProvider provideConfig
	 */
	public function testOnArticleRevisionVisibilitySet( array $config, bool $expectedJob ) {
		$now = 321;
		MWTimestamp::setFakeTime( $now );
		$title = $this->createMock( Title::class );
		$jobqueue = $this->createMock( JobQueueGroup::class );
		$expectedJobParam = [
			"update_kind" => "visibility_change",
			"root_event_time" => $now,
			"prioritize" => true
		];
		$jobqueue->expects( $this->exactly( $expectedJob ? 1 : 0 ) )
			->method( 'lazyPush' )
			->with( new CirrusLinksUpdate( $title, $expectedJobParam ) );
		$listener = new ChangeListener( $jobqueue, $this->newHashSearchConfig( $config ),
			$this->createNoOpMock( IConnectionProvider::class ),
			$this->createNoOpMock( RedirectLookup::class )
		);
		$listener->onArticleRevisionVisibilitySet( $title, [], [] );
	}

	/**
	 * @covers \CirrusSearch\ChangeListener::onPageDelete
	 * @dataProvider provideConfig
	 */
	public function testOnPageDelete( array $config, bool $expectedJob ) {
		$jobqueue = $this->createMock( JobQueueGroup::class );
		$redirectLookup = $this->createMock( RedirectLookup::class );
		$redirect = PageIdentityValue::localIdentity( 123, 0, 'Deleted_Redirect' );
		$page = PageIdentityValue::localIdentity( 124, 0, 'A_Page' );
		$deleter = $this->createNoOpMock( Authority::class );

		$target = Title::makeTitle( 0, 'Redir_Target' );

		$redirectLookup->expects( $this->exactly( $expectedJob ? 2 : 0 ) )
			->method( 'getRedirectTarget' )
			->willReturnMap( [
				[ $redirect, $target ],
				[ $page, null ]
			] );

		$jobqueue->expects( $this->exactly( $expectedJob ? 1 : 0 ) )
			->method( 'lazyPush' )
			->with( new CirrusLinksUpdate( $target, [] ) );

		$listener = new ChangeListener( $jobqueue, $this->newHashSearchConfig( $config ),
			$this->createNoOpMock( IConnectionProvider::class ), $redirectLookup );
		$listener->onPageDelete( $redirect, $deleter, 'unused', new \StatusValue(), false );
		$listener->onPageDelete( $page, $deleter, 'unused', new \StatusValue(), false );
	}
}
