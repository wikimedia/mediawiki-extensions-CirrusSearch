<?php

namespace CirrusSearch;

use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Page\WikiPage;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\Title\TitleValue;
use MediaWiki\User\UserIdentity;

/**
 * @covers \CirrusSearch\PageChangeTracker
 */
class PageChangeTrackerTest extends CirrusTestCase {
	private function pageIdentity( int $pageId ): ProperPageIdentity {
		return new PageIdentityValue( $pageId, 0, 'unused', false );
	}

	public function testOnPageDelete() {
		$tracker = new PageChangeTracker();
		$tracker->onPageDelete( $this->pageIdentity( 1 ),
			$this->createNoOpMock( Authority::class ),
			'', new \StatusValue(), false );
		$this->assertPageIsTracked( $tracker, 1 );
	}

	public function testOnPageMoveComplete() {
		$tracker = new PageChangeTracker();
		$tracker->onPageMoveComplete( new TitleValue( 0, '' ), new TitleValue( 0, '' ),
			$this->createNoOpMock( UserIdentity::class ), 1, 2, '',
			$this->createNoOpMock( RevisionRecord::class ) );
		$this->assertPageIsTracked( $tracker, 1 );
		$this->assertPageIsTracked( $tracker, 2 );
	}

	public function testOnPageDeleteComplete() {
		$tracker = new PageChangeTracker();
		$tracker->onPageDeleteComplete( $this->pageIdentity( 1 ),
			$this->createNoOpMock( Authority::class ),
			'', 1,
			$this->createNoOpMock( RevisionRecord::class ),
			$this->createNoOpMock( ManualLogEntry::class ), 1 );
		$this->assertPageIsTracked( $tracker, 1 );
	}

	public function testOnPageSaveComplete() {
		$tracker = new PageChangeTracker();
		$page = $this->createMock( WikiPage::class );
		$page->expects( $this->once() )
			->method( 'getId' )
			->willReturn( 1 );
		$editResult = new EditResult( false, 1, null, null, null, false, false, [] );
		$tracker->onPageSaveComplete( $page,
			$this->createNoOpMock( UserIdentity::class ), '', 0,
			$this->createNoOpMock( RevisionRecord::class ), $editResult );
		$this->assertPageIsTracked( $tracker, 1 );
		$nullEdit = new EditResult( false, 1, null, null, null, false, true, [] );
		$tracker->onPageSaveComplete( $page,
			$this->createNoOpMock( UserIdentity::class ), '', 0,
			$this->createNoOpMock( RevisionRecord::class ), $nullEdit );
		$this->assertFalse( $tracker->isPageChange( 2 ) );
	}

	public function testCapacity() {
		$tracker = new PageChangeTracker( 2 );
		foreach ( [ 3, 2, 1 ] as $id ) {
			$tracker->onPageDelete( $this->pageIdentity( $id ),
				$this->createNoOpMock( Authority::class ),
				'', new \StatusValue(), false );
		}
		$this->assertFalse( $tracker->isPageChange( 3 ) );
		$this->assertTrue( $tracker->isPageChange( 2 ) );
		$this->assertTrue( $tracker->isPageChange( 1 ) );
	}

	/**
	 * @param PageChangeTracker $tracker
	 * @param int $pageId
	 * @return void
	 */
	private function assertPageIsTracked( PageChangeTracker $tracker, int $pageId ): void {
		$this->assertTrue( $tracker->isPageChange( $pageId ) );
		$this->assertFalse( $tracker->isPageChange( $pageId ) );
	}
}
