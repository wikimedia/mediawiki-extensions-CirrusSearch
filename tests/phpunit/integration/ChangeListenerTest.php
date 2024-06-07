<?php

namespace CirrusSearch;

use CirrusSearch\Job\DeletePages;
use CirrusSearch\Job\LinksUpdate as CirrusLinksUpdate;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Deferred\LinksUpdate\LinksUpdate;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Page\RedirectLookup;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\MutableRevisionRecord;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\Title\Title;
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
	 * @covers \CirrusSearch\ChangeListener::prepareTitlesForLinksUpdate()
	 */
	public function testPrepareTitlesForLinksUpdate() {
		$changeListener = new ChangeListener(
			$this->createNoOpMock( \JobQueueGroup::class ),
			$this->newHashSearchConfig(),
			$this->createNoOpMock( IConnectionProvider::class ),
			$this->createNoOpMock( RedirectLookup::class )
		);
		$titles = [ Title::makeTitle( NS_MAIN, 'Title1' ), Title::makeTitle( NS_MAIN, 'Title2' ) ];
		$this->assertEqualsCanonicalizing(
			[ 'Title1', 'Title2' ],
			$changeListener->prepareTitlesForLinksUpdate( $titles, 2 ),
			'All titles must be returned'
		);
		$this->assertCount( 1, $changeListener->prepareTitlesForLinksUpdate( $titles, 1 ) );
		$titles = [ Title::makeTitle( NS_MAIN, 'Title1' ), Title::makeTitle( NS_MAIN, 'Title' . chr( 130 ) ) ];
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

		$jobqueue = $this->createMock( \JobQueueGroup::class );
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

		$page = $this->createMock( \WikiPage::class );
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
				],
				$setup[0],
				$setup[1]
			];
			$cases["simple page page refresh with $case"] = [
				123,
				static function ( ChangeListener $listener, \WikiPage $page ) {
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

		$uploadBase = $this->createMock( \UploadBase::class );
		$uploadBase->method( 'getTitle' )->willReturn( $title );

		$jobqueue = $this->createMock( \JobQueueGroup::class );
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
		$page = $this->createMock( \WikiPage::class );
		$title = $this->createMock( Title::class );
		$page->method( 'getTitle' )->willReturn( $title );
		$logEntry = $this->createMock( \ManualLogEntry::class );
		$logEntry->method( 'getTimestamp' )->willReturn( MWTimestamp::convert( TS_MW, $now ) );
		$jobqueue = $this->createMock( \JobQueueGroup::class );

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
		$jobqueue = $this->createMock( \JobQueueGroup::class );
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
		$jobqueue = $this->createMock( \JobQueueGroup::class );
		$redirectLookup = $this->createMock( RedirectLookup::class );
		$redirect = new PageIdentityValue( 123, 0, 'Deleted_Redirect', false );
		$page = new PageIdentityValue( 124, 0, 'A_Page', false );
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
