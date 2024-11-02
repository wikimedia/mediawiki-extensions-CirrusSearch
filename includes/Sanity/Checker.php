<?php

namespace CirrusSearch\Sanity;

use ArrayObject;
use CirrusSearch\Connection;
use CirrusSearch\SearchConfig;
use CirrusSearch\Searcher;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use Wikimedia\Stats\Metrics\CounterMetric;
use Wikimedia\Stats\Metrics\NullMetric;
use Wikimedia\Stats\StatsFactory;
use WikiPage;

/**
 * Checks if a WikiPage's representation in search index is sane.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */

class Checker {
	/**
	 * @var SearchConfig
	 */
	private $searchConfig;

	/**
	 * @var Connection
	 */
	private $connection;

	/**
	 * @var Searcher Used for fetching data, so we can check the content.
	 */
	private $searcher;

	/**
	 * @var Remediator Do something with the problems we found
	 */
	private $remediator;

	/**
	 * @var StatsFactory Used to record stats about the process
	 */
	private StatsFactory $statsFactory;

	/**
	 * @var bool Should we log id's that are found to have no problems
	 */
	private $logSane;

	/**
	 * @var bool inspect WikiPage::isRedirect() instead of WikiPage::getContent()->isRedirect()
	 * Faster since it does not need to fetch the content but inconsistent in some cases.
	 */
	private $fastRedirectCheck;

	/**
	 * A cache for pages loaded with loadPagesFromDB( $pageIds ). This is only
	 * useful when multiple Checker are run to check different elastic clusters.
	 * @var ArrayObject|null
	 */
	private $pageCache;

	/**
	 * @var callable Accepts a WikiPage argument and returns boolean true if the page
	 *  should be reindexed based on time since last reindex.
	 */
	private $isOldFn;

	/**
	 * Build the checker.
	 * @param SearchConfig $config
	 * @param Connection $connection
	 * @param Remediator $remediator the remediator to which to send titles
	 *   that are insane
	 * @param Searcher $searcher searcher to use for fetches
	 * @param StatsFactory $statsFactory to use for recording metrics
	 * @param bool $logSane should we log sane ids
	 * @param bool $fastRedirectCheck fast but inconsistent redirect check
	 * @param ArrayObject|null $pageCache cache for WikiPage loaded from db
	 * @param callable|null $isOldFn Accepts a WikiPage argument and returns boolean true if the page
	 *  should be reindexed based on time since last reindex.
	 */
	public function __construct(
		SearchConfig $config,
		Connection $connection,
		Remediator $remediator,
		Searcher $searcher,
		StatsFactory $statsFactory,
		$logSane,
		$fastRedirectCheck,
		?ArrayObject $pageCache = null,
		?callable $isOldFn = null
	) {
		$this->searchConfig = $config;
		$this->connection = $connection;
		$this->statsFactory = $statsFactory;
		$this->remediator = new CountingRemediator(
			$remediator,
			function ( string $problem ) {
				return $this->getCounter( "fixed", $problem );
			}
		);
		$this->searcher = $searcher;
		$this->logSane = $logSane;
		$this->fastRedirectCheck = $fastRedirectCheck;
		$this->pageCache = $pageCache;
		$this->isOldFn = $isOldFn ?? static function ( WikiPage $page ) {
			return false;
		};
	}

	/**
	 * Decide if a document should be reindexed based on time since last reindex
	 *
	 * Consider a page as old every $numCycles times the saneitizer loops over
	 * the same document. This ensures documents have been reindexed within the
	 * last `$numCycles * actual_loop_duration` (note that the configured
	 * duration is min_loop_duration, but in practice configuration ensures min
	 * and actual are typically the same).
	 *
	 * @param int $loopId The number of times the checker has looped over
	 *  the document set.
	 * @param int $numCycles The number of loops after which a document
	 *  is considered old.
	 * @return \Closure
	 */
	public static function makeIsOldClosure( $loopId, $numCycles ) {
		$loopMod = $loopId % $numCycles;
		return static function ( \WikiPage $page ) use ( $numCycles, $loopMod ) {
			$pageIdMod = $page->getId() % $numCycles;
			return $pageIdMod == $loopMod;
		};
	}

	/**
	 * Check if a title is insane.
	 *
	 * @param int[] $pageIds page to check
	 * @return int the number of pages updated
	 * @throws CheckerException
	 */
	public function check( array $pageIds ) {
		$docIds = array_map( [ $this->searchConfig, 'makeId' ], $pageIds );

		$pagesFromDb = $this->loadPagesFromDB( $pageIds );
		$pagesFromIndex = $this->loadPagesFromIndex( $docIds );
		$nbPagesFixed = 0;
		$nbPagesOld = 0;
		foreach ( array_combine( $pageIds, $docIds ) as $pageId => $docId ) {
			$fromIndex = [];
			if ( isset( $pagesFromIndex[$docId] ) ) {
				$fromIndex = $pagesFromIndex[$docId];
			}

			if ( isset( $pagesFromDb[$pageId] ) ) {
				$page = $pagesFromDb[$pageId];
				$updated = $this->checkExisitingPage( $docId, $pageId, $page, $fromIndex );
				if ( !$updated && ( $this->isOldFn )( $page ) ) {
					$this->remediator->oldDocument( $page );
					$nbPagesOld++;
				}
			} else {
				$updated = $this->checkInexistentPage( $docId, $pageId, $fromIndex );
			}
			if ( $updated ) {
				$nbPagesFixed++;
			}
		}
		$this->getCounter( "checked" )->incrementBy( count( $pageIds ) );
		// This is a duplicate of the "fixed" counter with the
		// "problem => oldDocument" label. It can be removed once
		// dashboards have transitioned away from statsd.
		$this->getCounter( "old" )->incrementBy( $nbPagesOld );

		return $nbPagesFixed;
	}

	/**
	 * @return CounterMetric|NullMetric
	 */
	private function getCounter( string $action, string $problem = "n/a" ) {
		$cluster = $this->connection->getClusterName();
		return $this->statsFactory->getCounter( "sanitization_total" )
			->setLabel( "problem", $problem )
			->setLabel( "search_cluster", $cluster )
			->setLabel( "action", $action )
			->copyToStatsdAt( "CirrusSearch.$cluster.sanitization.$action" );
	}

	/**
	 * Check that an existing page is properly indexed:
	 * - index it if missing in the index
	 * - delete it if it's a redirect
	 * - verify it if found in the index
	 *
	 * @param string $docId
	 * @param int $pageId
	 * @param WikiPage $page
	 * @param \Elastica\Result[] $fromIndex
	 * @return bool true if a modification was needed
	 */
	private function checkExisitingPage( $docId, $pageId, $page, array $fromIndex ) {
		$inIndex = $fromIndex !== [];
		if ( $this->checkIfRedirect( $page ) ) {
			if ( $inIndex ) {
				foreach ( $fromIndex as $indexInfo ) {
					$indexSuffix = $this->connection->extractIndexSuffix( $indexInfo->getIndex() );
					$this->remediator->redirectInIndex( $docId, $page, $indexSuffix );
				}
				return true;
			}
			$this->sane( $pageId, 'Redirect not in index' );
			return false;
		}
		if ( $inIndex ) {
			return $this->checkPageInIndex( $docId, $pageId, $page, $fromIndex );
		}
		$this->remediator->pageNotInIndex( $page );
		return true;
	}

	/**
	 * Check if the page is a redirect
	 * @param WikiPage $page
	 * @return bool true if $page is a redirect
	 */
	private function checkIfRedirect( $page ) {
		if ( $this->fastRedirectCheck ) {
			return $page->isRedirect();
		}

		$content = $page->getContent();
		if ( $content == null ) {
			return false;
		}
		if ( is_object( $content ) ) {
			return $content->isRedirect();
		}
		return false;
	}

	/**
	 * Check that an inexistent page is not present in the index
	 * and delete it if found
	 *
	 * @param string $docId
	 * @param int $pageId
	 * @param \Elastica\Result[] $fromIndex
	 * @return bool true if a modification was needed
	 */
	private function checkInexistentPage( $docId, $pageId, array $fromIndex ) {
		$inIndex = $fromIndex !== [];
		if ( $inIndex ) {
			foreach ( $fromIndex as $r ) {
				$title = Title::makeTitleSafe( $r->namespace, $r->title ) ??
					Title::makeTitle( NS_SPECIAL, 'Badtitle/InvalidInDBOrElastic' );
				$this->remediator->ghostPageInIndex( $docId, $title );
			}
			return true;
		}
		$this->sane( $pageId, 'No ghost' );
		return false;
	}

	/**
	 * Check that a page present in the db and in the index
	 * is in the correct index with the latest version.
	 *
	 * @param string $docId
	 * @param int $pageId
	 * @param WikiPage $page
	 * @param \Elastica\Result[] $fromIndex
	 * @return bool true if a modification was needed
	 */
	private function checkPageInIndex( $docId, $pageId, WikiPage $page, array $fromIndex ) {
		$insane = $this->checkIndexMismatch( $docId, $pageId, $page, $fromIndex );
		if ( !$insane ) {
			$insane = $this->checkIndexedVersion( $docId, $pageId, $page, $fromIndex );
		}

		if ( !$insane ) {
			$this->sane( $pageId, 'Page in index with latest version' );
		}

		return $insane;
	}

	/**
	 * Check that a page present in the db and in the index
	 * is properly indexed to the appropriate index by checking its
	 * namespace.
	 *
	 * @param string $docId
	 * @param int $pageId
	 * @param WikiPage $page
	 * @param \Elastica\Result[] $fromIndex
	 * @return bool true if a modification was needed
	 */
	private function checkIndexMismatch( $docId, $pageId, WikiPage $page, array $fromIndex ) {
		$foundInsanityInIndex = false;
		$expectedSuffix = $this->connection->getIndexSuffixForNamespace(
			$page->getTitle()->getNamespace()
		);
		foreach ( $fromIndex as $indexInfo ) {
			$suffix = $this->connection->extractIndexSuffix( $indexInfo->getIndex() );
			if ( $suffix !== $expectedSuffix ) {
				// Got to grab the index type from the index name....
				$this->remediator->pageInWrongIndex( $docId, $page, $suffix );
				$foundInsanityInIndex = true;
			}
		}

		if ( $foundInsanityInIndex ) {
			return true;
		}

		return false;
	}

	/**
	 * Check that the indexed version of the page is the
	 * latest version in the database.
	 *
	 * @param string $docId
	 * @param int $pageId
	 * @param WikiPage $page
	 * @param \Elastica\Result[] $fromIndex
	 * @return bool true if a modification was needed
	 */
	private function checkIndexedVersion( $docId, $pageId, WikiPage $page, array $fromIndex ) {
		$latest = $page->getLatest();
		$foundInsanityInIndex = false;
		foreach ( $fromIndex as $indexInfo ) {
			$version = $indexInfo->getSource()['version'] ?? -1;
			if ( $version < $latest ) {
				$type = $this->connection->extractIndexSuffix( $indexInfo->getIndex() );
				$this->remediator->oldVersionInIndex( $docId, $page, $type );

				$foundInsanityInIndex = true;
			}
		}

		return $foundInsanityInIndex;
	}

	/**
	 * @param int[] $pageIds
	 * @return WikiPage[] the list of wiki pages indexed in page id
	 */
	private function loadPagesFromDB( array $pageIds ) {
		// If no cache object is constructed we build a new one.
		// Building it in the constructor would cause memleaks because
		// there is no automatic prunning of old entries. If a cache
		// object is provided the owner of this Checker instance must take
		// care of the cleaning.
		$cache = $this->pageCache ?: new ArrayObject();
		$pageIds = array_diff( $pageIds, array_keys( $cache->getArrayCopy() ) );
		if ( !$pageIds ) {
			return $cache->getArrayCopy();
		}
		$dbr = $this->getDB();
		$pageQuery = WikiPage::getQueryInfo();

		$res = $dbr->newSelectQueryBuilder()
			->select( $pageQuery['fields'] )
			->tables( $pageQuery['tables'] )
			->where( [ 'page_id' => $pageIds ] )
			->caller( __METHOD__ )
			->joinConds( $pageQuery['joins'] )
			->fetchResultSet();

		$wikiPageFactory = MediaWikiServices::getInstance()->getWikiPageFactory();
		foreach ( $res as $row ) {
			$page = $wikiPageFactory->newFromRow( $row );
			if ( Title::newFromDBkey( $page->getTitle()->getPrefixedDBkey() ) === null ) {
				// The DB may contain invalid titles, make sure we try to sanitize only valid titles
				// invalid titles like this may have to wait for a dedicated clean up action
				continue;
			}
			$cache->offsetSet( $page->getId(), $page );
		}
		return $cache->getArrayCopy();
	}

	/**
	 * @return \Wikimedia\Rdbms\IReadableDatabase
	 */
	private function getDB() {
		return MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();
	}

	/**
	 * @param string[] $docIds document ids
	 * @return \Elastica\Result[][] search results indexed by page id
	 * @throws CheckerException if an error occurred
	 */
	private function loadPagesFromIndex( array $docIds ) {
		$status = $this->searcher->get( $docIds, [ 'namespace', 'title', 'version' ], false );
		if ( !$status->isOK() ) {
			throw new CheckerException( 'Cannot fetch ids from index' );
		}
		/** @var \Elastica\ResultSet $dataFromIndex */
		$dataFromIndex = $status->getValue();

		$indexedPages = [];
		foreach ( $dataFromIndex as $indexInfo ) {
			$indexedPages[$indexInfo->getId()][] = $indexInfo;
		}
		return $indexedPages;
	}

	private function sane( $pageId, $reason ) {
		if ( $this->logSane ) {
			printf( "%30s %10d\n", $reason, $pageId );
		}
	}
}
