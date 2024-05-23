<?php

namespace CirrusSearch;

use ManualLogEntry;
use MediaWiki\Hook\PageMoveCompleteHook;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\Hook\PageDeleteHook;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\User\UserIdentity;
use StatusValue;

/**
 * Listen to a set of hooks to keep track if a pageId was involved in a "page change".
 * A page change is a change happening to the page itself, based on the hooks used by EventBus
 * to emit its page_change stream we use this to determine in which stream we might emit a cirrus
 * event based on a LinksUpdateComplete hook. Mainly we want to identify if a particular LinksUpdate
 * is caused by a page change or something else unrelated to the life of the page.
 */
class PageChangeTracker implements
	PageSaveCompleteHook,
	PageMoveCompleteHook,
	PageDeleteCompleteHook,
	PageDeleteHook
{
	/**
	 * @var array<int,bool>
	 */
	private array $changedPages = [];
	private int $maxStateSize;

	public function __construct( int $maxStateSize = 512 ) {
		$this->maxStateSize = $maxStateSize;
	}

	private function flag( int $pageId ): void {
		if ( count( $this->changedPages ) >= $this->maxStateSize ) {
			$this->changedPages = array_slice( $this->changedPages, 1 - $this->maxStateSize, null, true );
		}
		$this->changedPages[$pageId] = true;
	}

	/**
	 * @param ProperPageIdentity $page
	 * @param Authority $deleter
	 * @param string $reason
	 * @param int $pageID
	 * @param RevisionRecord $deletedRev
	 * @param ManualLogEntry $logEntry
	 * @param int $archivedRevisionCount
	 * @return void
	 */
	public function onPageDeleteComplete(
		ProperPageIdentity $page,
		Authority $deleter,
		string $reason,
		int $pageID,
		RevisionRecord $deletedRev,
		ManualLogEntry $logEntry,
		int $archivedRevisionCount
	) {
		$this->flag( $pageID );
	}

	/**
	 * @param ProperPageIdentity $page
	 * @param Authority $deleter
	 * @param string $reason
	 * @param StatusValue $status
	 * @param bool $suppress
	 * @return void
	 */
	public function onPageDelete( ProperPageIdentity $page,
		Authority $deleter,
		string $reason,
		StatusValue $status,
		bool $suppress
	) {
		$this->flag( $page->getId() );
	}

	/**
	 * @param LinkTarget $old Old title
	 * @param LinkTarget $new New title
	 * @param UserIdentity $user User who did the move
	 * @param int $pageid Database ID of the page that's been moved
	 * @param int $redirid Database ID of the created redirect
	 * @param string $reason Reason for the move
	 * @param RevisionRecord $revision RevisionRecord created by the move
	 * @return bool|void True or no return value to continue or false stop other hook handlers,
	 *     doesn't abort the move itself
	 */
	public function onPageMoveComplete( $old, $new, $user, $pageid, $redirid, $reason, $revision ) {
		$this->flag( $pageid );
		$this->flag( $redirid );
	}

	/**
	 * @param \WikiPage $wikiPage WikiPage modified
	 * @param UserIdentity $user User performing the modification
	 * @param string $summary Edit summary/comment
	 * @param int $flags Flags passed to WikiPage::doUserEditContent()
	 * @param RevisionRecord $revisionRecord New RevisionRecord of the article
	 * @param EditResult $editResult Object storing information about the effects of this edit,
	 *   including which edits were reverted and which edit is this based on (for reverts and null
	 *   edits).
	 * @return bool|void True or no return value to continue or false to stop other hook handlers
	 *    from being called; save cannot be aborted
	 */
	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ) {
		if ( !$editResult->isNullEdit() ) {
			$this->flag( $wikiPage->getId() );
		}
	}

	/**
	 * Test if this pageId was references in a hook call earlier.
	 * Calling this function resets the state held by this class.
	 * @param int $pageId
	 * @return bool
	 */
	public function isPageChange( int $pageId ): bool {
		if ( array_key_exists( $pageId, $this->changedPages ) ) {
			unset( $this->changedPages[$pageId] );
			return true;
		}
		return false;
	}
}
