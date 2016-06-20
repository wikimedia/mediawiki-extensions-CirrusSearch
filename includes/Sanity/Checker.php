<?php

namespace CirrusSearch\Sanity;

use ArrayObject;
use CirrusSearch\Connection;
use CirrusSearch\Searcher;
use MediaWiki\MediaWikiServices;
use Status;
use Title;
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
	 * Build the checker.
	 * @param Connection $connection
	 * @param Remediator $remediator the remediator to which to send titles
	 *   that are insane
	 * @param Searcher $searcher searcher to use for fetches
	 * @param bool $logSane should we log sane ids
	 * @param bool $fastRedirectCheck fast but inconsistent redirect check
	 * @param ArrayObject|null $pageCache cache for WikiPage loaded from db
	 */
	public function __construct( Connection $connection, Remediator $remediator, Searcher $searcher, $logSane, $fastRedirectCheck, ArrayObject $pageCache = null ) {
		$this->connection = $connection;
		$this->remediator = $remediator;
		$this->searcher = $searcher;
		$this->logSane = $logSane;
		$this->fastRedirectCheck = $fastRedirectCheck;
		$this->pageCache = $pageCache;
	}

	/**
	 * Check if a title is insane.
	 *
	 * @param int[] $pageIds page to check
	 * @return int the number of pages updated
	 */
	public function check( array $pageIds ) {
		$pagesFromDb = $this->loadPagesFromDB( $pageIds );
		$pagesFromIndex = $this->loadPagesFromIndex( $pageIds );
		$nbPagesFixed = 0;
		foreach( $pageIds as $pageId ) {
			$fromIndex = array();
			if ( isset( $pagesFromIndex[$pageId] ) ) {
				$fromIndex = $pagesFromIndex[$pageId];
			}

			$updated = false;
			if ( isset ( $pagesFromDb[$pageId] ) ) {
				$page = $pagesFromDb[$pageId];
				$updated = $this->checkExisitingPage( $pageId, $page, $fromIndex );
			} else {
				$updated = $this->checkInexistentPage( $pageId, $fromIndex );
			}
			if( $updated ) {
				$nbPagesFixed++;
			}
		}
		$clusterName = $this->connection->getClusterName();
		$stats = MediaWikiServices::getInstance()->getStatsdDataFactory();
		$stats->updateCount( "CirrusSearch.$clusterName.sanitization.fixed", $nbPagesFixed );
		$stats->updateCount( "CirrusSearch.$clusterName.sanitization.checked", count( $pageIds ) );
		return $nbPagesFixed;
	}

	/**
	 * Check that an existing page is properly indexed:
	 * - index it if missing in the index
	 * - delete it if it's a redirect
	 * - verify it if found in the index
	 *
	 * @param int $pageId
	 * @param WikiPage $page
	 * @param \Elastica\Result[] $fromIndex
	 * @return bool true if a modification was needed
	 */
	private function checkExisitingPage( $pageId, $page, $fromIndex ) {
		$inIndex = count( $fromIndex ) > 0;
		if ( $this->checkIfRedirect( $page ) ) {
			if ( $inIndex ) {
				$this->remediator->redirectInIndex( $page );
				return true;
			}
			$this->sane( $pageId, 'Redirect not in index' );
			return false;
		}
		if ( $inIndex ) {
			return $this->checkIndexMismatch( $pageId, $page, $fromIndex );
		}
		$this->remediator->pageNotInIndex( $page );
		return true;
	}

	/**
	 * Check if the page is a redirect
	 * @param WikiPage $page the page
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
		if( is_object ( $content ) ) {
			return $content->isRedirect();
		}
		return false;
	}

	/**
	 * Check that an inexistent page is not present in the index
	 * and delete it if found
	 *
	 * @param int $pageId
	 * @param WikiPage $page
	 * @param \Elastica\Result[] $fromIndex
	 * @return bool true if a modification was needed
	 */
	private function checkInexistentPage( $pageId, $fromIndex ) {
		$inIndex = count( $fromIndex ) > 0;
		if ( $inIndex ) {
			foreach( $fromIndex as $r ) {
				$title = Title::makeTitle( $r->namespace, $r->title );
				$this->remediator->ghostPageInIndex( $pageId, $title );
			}
			return true;
		}
		$this->sane( $pageId, 'No ghost' );
		return false;
	}


	/**
	 * Check that a page present in the db and in the index
	 * is properly indexed to the appropriate index by checking its
	 * namespace.
	 *
	 * @param int $pageId
	 * @param WikiPage $page
	 * @param \Elastica\Result[] $fromIndex
	 * @return bool true if a modification was needed
	 */
	private function checkIndexMismatch( $pageId, $page, $fromIndex ) {
		$foundInsanityInIndex = false;
		$expectedType = $this->connection->getIndexSuffixForNamespace( $page->getTitle()->getNamespace() );
		foreach ( $fromIndex as $indexInfo ) {
			$matches = array();
			if ( !preg_match( '/_(.+)_.+$/', $indexInfo->getIndex(), $matches ) ) {
				throw new \Exception( "Can't parse index name:  " . $indexInfo->getIndex() );
			}
			$type = $matches[ 1 ];
			if ( $type !== $expectedType ) {
				// Got to grab the index type from the index name....
				$this->remediator->pageInWrongIndex( $page, $type );
				$foundInsanityInIndex = true;
			}
		}
		if ( $foundInsanityInIndex ) {
			return true;
		}
		$this->sane( $pageId, 'Page in index' );
		return false;
	}


	/**
	 * @param int[] $ids page ids
	 * @return WikiPage[] the list of wiki pages indexed in page id
	 */
	private function loadPagesFromDB( array $ids ) {
		// If no cache object is constructed we build a new one.
		// Building it in the constructor would cause memleaks because
		// there is no automatic prunning of old entries. If a cache
		// object is provided the owner of this Checker instance must take
		// care of the cleaning.
		$cache = $this->pageCache ?: new ArrayObject();
		$ids = array_diff( $ids, array_keys( $cache->getArrayCopy() ) );
		if ( empty( $ids ) ) {
			return $cache->getArrayCopy();
		}
		$dbr = wfGetDB( DB_SLAVE );
		$where = 'page_id IN (' . $dbr->makeList( $ids ) . ')';
		$res = $dbr->select(
			array( 'page' ),
			WikiPage::selectFields(),
			$where,
			__METHOD__
		);
		foreach ( $res as $row ) {
			$page = WikiPage::newFromRow( $row );
			$cache->offsetSet( $page->getId(), $page );
		}
		return $cache->getArrayCopy();
	}

	/**
	 * @param int[] $ids page ids
	 * @return \Elastica\Result[][] search results indexed by page id
	 * @throws \Exception if an error occurred
	 */
	private function loadPagesFromIndex( array $ids ) {
		$status = $this->searcher->get( $ids, array( 'namespace', 'title' ) );
		if ( !$status->isOK() ) {
			throw new \Exception( 'Cannot fetch ids from index' );
		}
		/** @var \Elastica\ResultSet $dataFromIndex */
		$dataFromIndex = $status->getValue();

		$indexedPages = array();
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
