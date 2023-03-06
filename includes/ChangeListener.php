<?php

namespace CirrusSearch;

use ConfigFactory;
use JobQueueGroup;
use LoadBalancer;
use MediaWiki\Hook\ArticleRevisionVisibilitySetHook;
use MediaWiki\Hook\LinksUpdateCompleteHook;
use MediaWiki\Hook\PageMoveCompleteHook;
use MediaWiki\Hook\TitleMoveHook;
use MediaWiki\Hook\UploadCompleteHook;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Page\Hook\ArticleDeleteCompleteHook;
use MediaWiki\Page\Hook\ArticleDeleteHook;
use MediaWiki\Page\Hook\ArticleUndeleteHook;
use Status;
use Title;
use User;
use WikiPage;

/**
 * Implementation to all the hooks that CirrusSearch needs to listen in order to keep its index
 * in sync with main SQL database.
 */
class ChangeListener implements
	LinksUpdateCompleteHook,
	TitleMoveHook,
	PageMoveCompleteHook,
	UploadCompleteHook,
	ArticleRevisionVisibilitySetHook,
	ArticleDeleteHook,
	ArticleDeleteCompleteHook,
	ArticleUndeleteHook
{
	/** @var JobQueueGroup */
	private $jobQueue;
	/** @var SearchConfig */
	private $searchConfig;
	/** @var LoadBalancer */
	private $loadBalancer;
	/** @var Connection */
	private $connection;

	/** @var array state holding the titles being moved */
	private $movingTitles = [];

	public static function create( JobQueueGroup $jobQueue, ConfigFactory $configFactory, LoadBalancer $loadBalancer ): ChangeListener {
		/** @phan-suppress-next-line PhanTypeMismatchArgumentSuperType $config is actually a SearchConfig */
		return new self( $jobQueue, $configFactory->makeConfig( "CirrusSearch" ), $loadBalancer );
	}

	/**
	 * @param JobQueueGroup $jobQueue
	 * @param SearchConfig $searchConfig
	 * @param LoadBalancer $loadBalancer
	 */
	public function __construct( JobQueueGroup $jobQueue, SearchConfig $searchConfig, LoadBalancer $loadBalancer ) {
		$this->jobQueue = $jobQueue;
		$this->searchConfig = $searchConfig;
		$this->loadBalancer = $loadBalancer;
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
		$this->jobQueue->push(
			new Job\LinksUpdate( $title, [
				'prioritize' => true
			] )
		);
	}

	/**
	 * Hooked to update the search index when pages change directly or when templates that
	 * they include change.
	 * @param \LinksUpdate $linksUpdate
	 * @param mixed $ticket Prior result of LBFactory::getEmptyTransactionTicket()
	 */
	public function onLinksUpdateComplete( $linksUpdate, $ticket ) {
		$linkedArticlesToUpdate = $this->searchConfig->get( 'CirrusSearchLinkedArticlesToUpdate' );
		$unLinkedArticlesToUpdate = $this->searchConfig->get( 'CirrusSearchUnlinkedArticlesToUpdate' );
		$updateDelay = $this->searchConfig->get( 'CirrusSearchUpdateDelay' );

		// Titles that are created by a move don't need their own job.
		if ( in_array( $linksUpdate->getTitle()->getPrefixedDBkey(), $this->movingTitles ) ) {
			return;
		}

		$params = [];
		if ( $this->searchConfig->get( 'CirrusSearchEnableIncomingLinkCounting' ) ) {
			$params['addedLinks'] = self::prepareTitlesForLinksUpdate( $linksUpdate->getAddedLinks(),
					$linkedArticlesToUpdate );
			// We exclude links that contains invalid UTF-8 sequences, reason is that page created
			// before T13143 was fixed might sill have bad links the pagelinks table
			// and thus will cause LinksUpdate to believe that these links are removed.
			$params['removedLinks'] = self::prepareTitlesForLinksUpdate( $linksUpdate->getRemovedLinks(),
					$unLinkedArticlesToUpdate, true );
		}
			// non recursive LinksUpdate can go to the non prioritized queue
		if ( $linksUpdate->isRecursive() ) {
			$params[ 'prioritize' ] = true;
			$delay = $updateDelay['prioritized'];
		} else {
			$delay = $updateDelay['default'];
		}
		$params += Job\LinksUpdate::buildJobDelayOptions( Job\LinksUpdate::class, $delay );
		$job = new Job\LinksUpdate( $linksUpdate->getTitle(), $params );

		$this->jobQueue->lazyPush( $job );
	}

	/**
	 * Hook into UploadComplete, overwritten files do not seem to trigger LinksUpdateComplete.
	 * Since files do contain indexed metadata we need to refresh the search index when a file
	 * is overwritten on an existing title.
	 * @param \UploadBase $uploadBase
	 */
	public function onUploadComplete( $uploadBase ) {
		if ( $uploadBase->getTitle()->exists() ) {
			$this->jobQueue->push(
				new Job\LinksUpdate( $uploadBase->getTitle(), [
					'prioritize' => true
				] )
			);
		}
	}

	/**
	 * Hook to call before an article is deleted
	 * @param WikiPage $page WikiPage being deleted
	 * @param User $user User deleting the article
	 * @param string &$reason Reason the article is being deleted
	 * @param string &$error If the deletion was prohibited, the (raw HTML) error message to display
	 *   (added in 1.13)
	 * @param Status &$status Modify this to throw an error. Overridden by $error
	 *   (added in 1.20)
	 * @param bool $suppress Whether this is a suppression deletion or not (added in 1.27)
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onArticleDelete( WikiPage $page, User $user, &$reason, &$error, Status &$status, $suppress ) {
		// We use this to pick up redirects so we can update their targets.
		// Can't re-use ArticleDeleteComplete because the page info's
		// already gone
		// If we abort or fail deletion it's no big deal because this will
		// end up being a no-op when it executes.
		$target = $page->getRedirectTarget();
		if ( $target ) {
			// DeferredUpdate so we don't end up racing our own page deletion
			\DeferredUpdates::addCallableUpdate( function () use ( $target ) {
				$this->jobQueue->push(
					new Job\LinksUpdate( $target, [] )
				);
			} );
		}
	}

	/**
	 * Hook to call after an article is deleted
	 * @param WikiPage $wikiPage WikiPage that was deleted
	 * @param User $user User that deleted the article
	 * @param string $reason Reason the article was deleted
	 * @param int $id ID of the article that was deleted
	 * @param \Content|null $content Content of the deleted page (or null, when deleting a broken page)
	 * @param \ManualLogEntry $logEntry ManualLogEntry used to record the deletion
	 * @param int $archivedRevisionCount Number of revisions archived during the deletion
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onArticleDeleteComplete( $wikiPage, $user, $reason, $id, $content, $logEntry, $archivedRevisionCount ) {
		// Note that we must use the article id provided or it'll be lost in the ether.  The job can't
		// load it from the title because the page row has already been deleted.
		$this->jobQueue->push(
			new Job\DeletePages( $wikiPage->getTitle(), [
				'docId' => $this->searchConfig->makeId( $id )
			] )
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
			$this->loadBalancer->getConnectionRef( DB_PRIMARY )->onTransactionCommitOrIdle( function () use ( $job ) {
				$this->jobQueue->lazyPush( $job );
			}, __METHOD__ );
		}
	}

	/**
	 * Take a list of titles either linked or unlinked and prepare them for Job\LinksUpdate.
	 * This includes limiting them to $max titles.
	 * @param Title[] $titles titles to prepare
	 * @param int $max maximum number of titles to return
	 * @param bool $excludeBadUTF exclude links that contains invalid UTF sequences
	 * @return array
	 */
	public static function prepareTitlesForLinksUpdate( $titles, int $max, $excludeBadUTF = false ) {
		$titles = self::pickFromArray( $titles, $max );
		$dBKeys = [];
		foreach ( $titles as $title ) {
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

	/**
	 * When article is undeleted - check the archive for other instances of the title,
	 * if not there - drop it from the archive.
	 *
	 * @param Title $title Title corresponding to the article restored
	 * @param bool $create Whether or not the restoration caused the page to be created (i.e. it
	 *   didn't exist before)
	 * @param string $comment Comment associated with the undeletion
	 * @param int $oldPageId ID of page previously deleted (from archive table). This ID will be used
	 *   for the restored page.
	 * @param array $restoredPages Set of page IDs that have revisions restored for this undelete,
	 *   with keys set to page IDs and values set to 'true'
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onArticleUndelete( $title, $create, $comment, $oldPageId, $restoredPages ) {
		if ( !$this->searchConfig->get( 'CirrusSearchIndexDeletes' ) ) {
			// Not indexing, thus nothing to remove here.
			return;
		}
		$this->jobQueue->push(
			new Job\DeleteArchive( $title, [ 'docIds' => $restoredPages ] )
		);
	}

}
