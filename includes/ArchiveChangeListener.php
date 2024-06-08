<?php

namespace CirrusSearch;

use CirrusSearch\Job\DeleteArchive;
use CirrusSearch\Job\IndexArchive;
use JobQueueGroup;
use ManualLogEntry;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\Hook\PageUndeleteCompleteHook;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
use MediaWiki\Utils\MWTimestamp;
use Wikimedia\Assert\Assert;

/**
 * Change listener responsible for writing to the archive index.
 */
class ArchiveChangeListener implements PageDeleteCompleteHook, PageUndeleteCompleteHook {
	private JobQueueGroup $jobQueue;
	private SearchConfig $searchConfig;

	public function __construct( JobQueueGroup $jobQueue, SearchConfig $searchConfig ) {
		$this->jobQueue = $jobQueue;
		$this->searchConfig = $searchConfig;
	}

	public static function create(
		JobQueueGroup $jobQueue,
		ConfigFactory $configFactory
	): ArchiveChangeListener {
		/** @phan-suppress-next-line PhanTypeMismatchArgumentSuperType $config is actually a SearchConfig */
		return new self( $jobQueue, $configFactory->makeConfig( "CirrusSearch" ) );
	}

	private function isEnabled(): bool {
		if ( !$this->searchConfig->get( 'CirrusSearchIndexDeletes' ) ) {
			return false;
		}
		return $this->searchConfig
				   ->getClusterAssignment()
				   ->getWritableClusters( UpdateGroup::ARCHIVE ) != [];
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
	public function onPageDeleteComplete( ProperPageIdentity $page, Authority $deleter,
		string $reason, int $pageID, RevisionRecord $deletedRev, ManualLogEntry $logEntry,
		int $archivedRevisionCount
	) {
		if ( !$this->isEnabled() ) {
			// Not indexing, thus nothing to remove here.
			return;
		}
		// Note that we must use the article id provided or it'll be lost in the ether.  The job can't
		// load it from the title because the page row has already been deleted.
		$title = Title::castFromPageIdentity( $page );
		Assert::postcondition( $title !== null, '$page can be cast to a Title' );
		$this->jobQueue->lazyPush(
			IndexArchive::build(
				$title,
				$this->searchConfig->makeId( $pageID ),
				$logEntry->getTimestamp() !== false ? MWTimestamp::convert( TS_UNIX, $logEntry->getTimestamp() ) : MWTimestamp::time()
			)
		);
	}

	/**
	 * When article is undeleted - check the archive for other instances of the title,
	 * if not there - drop it from the archive.
	 * @param ProperPageIdentity $page
	 * @param Authority $restorer
	 * @param string $reason
	 * @param RevisionRecord $restoredRev
	 * @param ManualLogEntry $logEntry
	 * @param int $restoredRevisionCount
	 * @param bool $created
	 * @param array $restoredPageIds
	 * @return void
	 */
	public function onPageUndeleteComplete(
		ProperPageIdentity $page,
		Authority $restorer,
		string $reason,
		RevisionRecord $restoredRev,
		ManualLogEntry $logEntry,
		int $restoredRevisionCount,
		bool $created,
		array $restoredPageIds
	): void {
		if ( !$this->isEnabled() ) {
			// Not indexing, thus nothing to remove here.
			return;
		}
		$title = Title::castFromPageIdentity( $page );
		Assert::postcondition( $title !== null, '$page can be cast to a Title' );
		$this->jobQueue->lazyPush(
			new DeleteArchive( $title, [ 'docIds' => $restoredPageIds ] )
		);
	}
}
