<?php

namespace CirrusSearch;

use CirrusSearch\Job\CirrusTitleJob;
use CirrusSearch\Job\DeletePages;
use CirrusSearch\Job\LinksUpdate;
use JobQueueGroup;
use ManualLogEntry;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Deferred\LinksUpdate\LinksTable;
use MediaWiki\Hook\ArticleRevisionVisibilitySetHook;
use MediaWiki\Hook\LinksUpdateCompleteHook;
use MediaWiki\Hook\PageMoveCompleteHook;
use MediaWiki\Hook\TitleMoveHook;
use MediaWiki\Hook\UploadCompleteHook;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\Hook\PageDeleteHook;
use MediaWiki\Page\PageReference;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Page\RedirectLookup;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\Utils\MWTimestamp;
use Wikimedia\Assert\Assert;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * Implementation to all the hooks that CirrusSearch needs to listen in order to keep its index
 * in sync with main SQL database.
 */
class ChangeListener extends PageChangeTracker implements
	LinksUpdateCompleteHook,
	TitleMoveHook,
	PageMoveCompleteHook,
	UploadCompleteHook,
	ArticleRevisionVisibilitySetHook,
	PageDeleteHook,
	PageDeleteCompleteHook
{
	private JobQueueGroup $jobQueue;
	private SearchConfig $searchConfig;
	private IConnectionProvider $dbProvider;
	private RedirectLookup $redirectLookup;

	/** @var Connection */
	private $connection;

	/** @var array state holding the titles being moved */
	private $movingTitles = [];

	public static function create(
		JobQueueGroup $jobQueue,
		ConfigFactory $configFactory,
		IConnectionProvider $dbProvider,
		RedirectLookup $redirectLookup
	): ChangeListener {
		/** @phan-suppress-next-line PhanTypeMismatchArgumentSuperType $config is actually a SearchConfig */
		return new self( $jobQueue, $configFactory->makeConfig( "CirrusSearch" ), $dbProvider, $redirectLookup );
	}

	public function __construct(
		JobQueueGroup $jobQueue,
		SearchConfig $searchConfig,
		IConnectionProvider $dbProvider,
		RedirectLookup $redirectLookup
	) {
		parent::__construct();
		$this->jobQueue = $jobQueue;
		$this->searchConfig = $searchConfig;
		$this->dbProvider = $dbProvider;
		$this->redirectLookup = $redirectLookup;
	}

	/**
	 * Check whether at least one cluster is writeable or not.
	 * If not there are no reasons to schedule a job.
	 *
	 * @return bool true if at least one cluster is writeable
	 */
	private function isEnabled(): bool {
		return $this->searchConfig
			->getClusterAssignment()
			->getWritableClusters( UpdateGroup::PAGE ) != [];
	}

	/**
	 * Called when a revision is deleted. In theory, we shouldn't need to to this since
	 * you can't delete the current text of a page (so we should've already updated when
	 * the page was updated last). But we're paranoid, because deleted revisions absolutely
	 * should not be in the index.
	 *
	 * @param Title $title The page title we've had a revision deleted on
	 * @param int[] $ids IDs to set the visibility for
	 * @param array $visibilityChangeMap Map of revision ID to oldBits and newBits.
	 *   This array can be examined to determine exactly what visibility bits
	 *   have changed for each revision. This array is of the form:
	 *   [id => ['oldBits' => $oldBits, 'newBits' => $newBits], ... ]
	 */
	public function onArticleRevisionVisibilitySet( $title, $ids, $visibilityChangeMap ) {
		if ( !$this->isEnabled() ) {
			return;
		}
		$this->jobQueue->lazyPush( LinksUpdate::newPastRevisionVisibilityChange( $title ) );
	}

	/**
	 * Hooked to update the search index when pages change directly or when templates that
	 * they include change.
	 * @param \MediaWiki\Deferred\LinksUpdate\LinksUpdate $linksUpdate
	 * @param mixed $ticket Prior result of LBFactory::getEmptyTransactionTicket()
	 */
	public function onLinksUpdateComplete( $linksUpdate, $ticket ) {
		if ( !$this->isEnabled() ) {
			return;
		}
		// defer processing the LinksUpdateComplete hook until other hooks tagged in PageChangeTracker
		// have a chance to run. Reason is that we want to detect what are the links updates triggered
		// by a "page change". The definition of a "page change" we use is the one used by EventBus
		// PageChangeHooks.
		DeferredUpdates::addCallableUpdate( function () use ( $linksUpdate ) {
			$linkedArticlesToUpdate = $this->searchConfig->get( 'CirrusSearchLinkedArticlesToUpdate' );
			$unLinkedArticlesToUpdate = $this->searchConfig->get( 'CirrusSearchUnlinkedArticlesToUpdate' );
			$updateDelay = $this->searchConfig->get( 'CirrusSearchUpdateDelay' );

			// Titles that are created by a move don't need their own job.
			if ( in_array( $linksUpdate->getTitle()->getPrefixedDBkey(), $this->movingTitles ) ) {
				return;
			}

			$params = [];
			if ( $this->searchConfig->get( 'CirrusSearchEnableIncomingLinkCounting' ) ) {
				$params['addedLinks'] = self::preparePageReferencesForLinksUpdate(
					$linksUpdate->getPageReferenceArray( 'pagelinks', LinksTable::INSERTED ),
					$linkedArticlesToUpdate
				);
				// We exclude links that contains invalid UTF-8 sequences, reason is that page created
				// before T13143 was fixed might sill have bad links the pagelinks table
				// and thus will cause LinksUpdate to believe that these links are removed.
				$params['removedLinks'] = self::preparePageReferencesForLinksUpdate(
					$linksUpdate->getPageReferenceArray( 'pagelinks', LinksTable::DELETED ),
					$unLinkedArticlesToUpdate,
					true
				);
			}

			if ( $this->isPageChange( $linksUpdate->getPageId() ) ) {
				$jobParams = $params + LinksUpdate::buildJobDelayOptions( LinksUpdate::class,
						$updateDelay['prioritized'], $this->jobQueue );
				$job = LinksUpdate::newPageChangeUpdate( $linksUpdate->getTitle(),
					$linksUpdate->getRevisionRecord(), $jobParams );
				if ( ( MWTimestamp::time() - $job->params[CirrusTitleJob::ROOT_EVENT_TIME] ) > ( 3600 * 24 ) ) {
					LoggerFactory::getInstance( 'CirrusSearch' )->debug(
						"Scheduled a page-change-update for {title} on a revision created more than 24hours ago, " .
						"the cause is {causeAction}",
						[
							'title' => $linksUpdate->getTitle()->getPrefixedDBkey(),
							'causeAction' => $linksUpdate->getCauseAction()
						] );
				}
			} else {
				$job = LinksUpdate::newPageRefreshUpdate( $linksUpdate->getTitle(),
					$params + LinksUpdate::buildJobDelayOptions( LinksUpdate::class, $updateDelay['default'], $this->jobQueue ) );
			}

			$this->jobQueue->lazyPush( $job );
		} );
	}

	/**
	 * Hook into UploadComplete, because overwritten files mistakenly do not trigger
	 * LinksUpdateComplete (T344285). Since files do contain indexed metadata
	 * we need to refresh the search index when a file is overwritten on an
	 * existing title.
	 *
	 * @param \UploadBase $uploadBase
	 */
	public function onUploadComplete( $uploadBase ) {
		if ( !$this->isEnabled() ) {
			return;
		}
		if ( $uploadBase->getTitle()->exists() ) {
			$this->jobQueue->lazyPush( LinksUpdate::newPageChangeUpdate( $uploadBase->getTitle(), null, [] ) );
		}
	}

	/**
	 * This hook is called before a page is deleted.
	 *
	 * @since 1.37
	 *
	 * @param ProperPageIdentity $page Page being deleted.
	 * @param Authority $deleter Who is deleting the page
	 * @param string $reason Reason the page is being deleted
	 * @param \StatusValue $status Add any error here
	 * @param bool $suppress Whether this is a suppression deletion or not
	 * @return bool|void True or no return value to continue; false to abort, which also requires adding
	 * a fatal error to $status.
	 */
	public function onPageDelete(
		ProperPageIdentity $page,
		Authority $deleter,
		string $reason,
		\StatusValue $status,
		bool $suppress
	) {
		if ( !$this->isEnabled() ) {
			return;
		}
		parent::onPageDelete( $page, $deleter, $reason, $status, $suppress );
		// We use this to pick up redirects so we can update their targets.
		// Can't re-use PageDeleteComplete because the page info's
		// already gone
		// If we abort or fail deletion it's no big deal because this will
		// end up being a no-op when it executes.
		$targetLink = $this->redirectLookup->getRedirectTarget( $page );
		$target = null;
		if ( $targetLink != null ) {
			$target = Title::castFromLinkTarget( $targetLink );
		}
		if ( $target ) {
			$this->jobQueue->lazyPush( new Job\LinksUpdate( $target, [] ) );
		}
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
			return;
		}
		parent::onPageDeleteComplete( $page, $deleter, $reason, $pageID, $deletedRev, $logEntry, 1 );
		// Note that we must use the article id provided or it'll be lost in the ether.  The job can't
		// load it from the title because the page row has already been deleted.
		$title = Title::castFromPageIdentity( $page );
		Assert::postcondition( $title !== null, '$page can be cast to a Title' );
		$this->jobQueue->lazyPush(
			DeletePages::build(
				$title,
				$this->searchConfig->makeId( $pageID ),
				$logEntry->getTimestamp() !== false ? MWTimestamp::convert( TS_UNIX, $logEntry->getTimestamp() ) : MWTimestamp::time()
			)
		);
	}

	/**
	 * Before we've moved a title from $title to $newTitle.
	 *
	 * @param Title $old Old title
	 * @param Title $nt New title
	 * @param User $user User who does the move
	 * @param string $reason Reason provided by the user
	 * @param Status &$status To abort the move, add a fatal error to this object
	 *   	(i.e. call $status->fatal())
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onTitleMove( Title $old, Title $nt, User $user, $reason, Status &$status ) {
		if ( !$this->isEnabled() ) {
			return;
		}
		$this->movingTitles[] = $old->getPrefixedDBkey();
	}

	/**
	 * When we've moved a Title from A to B.
	 * @param \MediaWiki\Linker\LinkTarget $old Old title
	 * @param \MediaWiki\Linker\LinkTarget $new New title
	 * @param \MediaWiki\User\UserIdentity $user User who did the move
	 * @param int $pageid Database ID of the page that's been moved
	 * @param int $redirid Database ID of the created redirect
	 * @param string $reason Reason for the move
	 * @param \MediaWiki\Revision\RevisionRecord $revision RevisionRecord created by the move
	 * @return bool|void True or no return value to continue or false stop other hook handlers,
	 *     doesn't abort the move itself
	 */
	public function onPageMoveComplete(
		$old, $new, $user, $pageid, $redirid,
		$reason, $revision
	) {
		if ( !$this->isEnabled() ) {
			return;
		}
		parent::onPageMoveComplete( $old, $new, $user, $pageid, $redirid, $reason, $revision );
		// When a page is moved the update and delete hooks are good enough to catch
		// almost everything.  The only thing they miss is if a page moves from one
		// index to another.  That only happens if it switches namespace.
		if ( $old->getNamespace() === $new->getNamespace() ) {
			return;
		}

		$conn = $this->getConnection();
		$oldIndexSuffix = $conn->getIndexSuffixForNamespace( $old->getNamespace() );
		$newIndexSuffix = $conn->getIndexSuffixForNamespace( $new->getNamespace() );
		if ( $oldIndexSuffix !== $newIndexSuffix ) {
			$title = Title::newFromLinkTarget( $old );
			$job = new Job\DeletePages( $title, [
				'indexSuffix' => $oldIndexSuffix,
				'docId' => $this->searchConfig->makeId( $pageid )
			] );
			// Push the job after DB commit but cancel on rollback
			$this->dbProvider->getPrimaryDatabase()->onTransactionCommitOrIdle( function () use ( $job ) {
				$this->jobQueue->lazyPush( $job );
			}, __METHOD__ );
		}
	}

	/**
	 * Take a list of titles either linked or unlinked and prepare them for Job\LinksUpdate.
	 * This includes limiting them to $max titles.
	 * @param PageReference[] $pageReferences titles to prepare
	 * @param int $max maximum number of titles to return
	 * @param bool $excludeBadUTF exclude links that contains invalid UTF sequences
	 * @return array
	 */
	public static function preparePageReferencesForLinksUpdate( $pageReferences, int $max, $excludeBadUTF = false ) {
		$pageReferences = self::pickFromArray( $pageReferences, $max );
		$dBKeys = [];
		foreach ( $pageReferences as $pageReference ) {
			$title = Title::newFromPageReference( $pageReference );
			$key = $title->getPrefixedDBkey();
			if ( $excludeBadUTF ) {
				$fixedKey = mb_convert_encoding( $key, 'UTF-8', 'UTF-8' );
				if ( $fixedKey !== $key ) {
					LoggerFactory::getInstance( 'CirrusSearch' )
						->warning( "Ignoring title {title} with invalid UTF-8 sequences.",
							[ 'title' => $fixedKey ] );
					continue;
				}
			}
			$dBKeys[] = $title->getPrefixedDBkey();
		}
		return $dBKeys;
	}

	/**
	 * Pick $num random entries from $array.
	 * @param array $array Array to pick from
	 * @param int $num Number of entries to pick
	 * @return array of entries from $array
	 */
	private static function pickFromArray( $array, $num ) {
		if ( $num > count( $array ) ) {
			return $array;
		}
		if ( $num < 1 ) {
			return [];
		}
		$chosen = array_rand( $array, $num );
		// If $num === 1 then array_rand will return a key rather than an array of keys.
		if ( !is_array( $chosen ) ) {
			return [ $array[ $chosen ] ];
		}
		$result = [];
		foreach ( $chosen as $key ) {
			$result[] = $array[ $key ];
		}
		return $result;
	}

	private function getConnection(): Connection {
		if ( $this->connection === null ) {
			$this->connection = new Connection( $this->searchConfig );
		}
		return $this->connection;
	}
}
