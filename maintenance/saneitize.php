<?php

namespace CirrusSearch;

use CirrusSearch\Maintenance\Maintenance;
use CirrusSearch\Sanity\Checker;
use CirrusSearch\Sanity\NoopRemediator;
use CirrusSearch\Sanity\PrintingRemediator;
use CirrusSearch\Sanity\QueueingRemediator;

/**
 * Make sure the index for the wiki is sane.
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

$IP = getenv( 'MW_INSTALL_PATH' );
if( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once( "$IP/maintenance/Maintenance.php" );
require_once( __DIR__ . '/../includes/Maintenance/Maintenance.php' );

class Saneitize extends Maintenance {
	/**
	 * @var int mediawiki page id
	 */
	private $fromId;

	/**
	 * @var int mediawiki page id
	 */
	private $toId;

	/**
	 * @var Checker Checks is the index is insane, and calls on a Remediator
	 *  instance to do something about it. The remediator may fix the issue,
	 *  log about it, or do a combination.
	 */
	private $checker;

	public function __construct() {
		parent::__construct();
		$this->setBatchSize( 10 );
		$this->mDescription = "Make the index sane. Always operates on a single cluster.";
		$this->addOption( 'fromId', 'Start sanitizing at a specific page_id.  Default to 0.', false, true );
		$this->addOption( 'toId', 'Stop sanitizing at a specific page_id.  Default to the maximum id in the db + 100.', false, true );
		$this->addOption( 'noop', 'Rather then queue remediation actions do nothing.' );
		$this->addOption( 'logSane', 'Print all sane pages.' );
		$this->addOption( 'buildChunks', 'Instead of running the script spit out commands that can be farmed out to ' .
			'different processes or machines to check the index.  If specified as a number then chunks no larger than ' .
			'that size are spat out.  If specified as a number followed by the word "total" without a space between them ' .
			'then that many chunks will be spat out sized to cover the entire wiki.' , false, true );
	}

	public function execute() {
		global $wgCirrusSearchMaintenanceTimeout,
			$wgCirrusSearchClientSideUpdateTimeout;

		$this->disablePoolCountersAndLogging();

		// Set the timeout for maintenance actions
		$this->getConnection()->setTimeout( $wgCirrusSearchMaintenanceTimeout );
		$wgCirrusSearchClientSideUpdateTimeout = $wgCirrusSearchMaintenanceTimeout;

		if ( $this->hasOption( 'batch-size' ) ) {
			$this->setBatchSize( $this->getOption( 'batch-size' ) );
			if ( $this->mBatchSize > 5000 ) {
				$this->error( "--batch-size too high!", 1 );
			} elseif ( $this->mBatchSize <= 0 ) {
				$this->error( "--batch-size must be > 0!", 1 );
			}

		}
		$this->setFromAndTo();
		$buildChunks = $this->getOption( 'buildChunks');
		if ( $buildChunks ) {
			$builder = new \CirrusSearch\Maintenance\ChunkBuilder();
			$builder->build( $this->mSelf, $this->mOptions, $buildChunks, $this->fromId, $this->toId );
			return;
		}
		$this->buildChecker();
		$updated = $this->check();
		$this->output( "Fixed $updated page(s) (" . ( $this->toId - $this->fromId ) . " checked)" );
	}

	/**
	 * @return int the number of pages corrected
	 */
	private function check() {
		$updated = 0;
		for ( $pageId = $this->fromId; $pageId <= $this->toId; $pageId += $this->mBatchSize ) {
			$max = min( $this->toId, $pageId + $this->mBatchSize - 1 );
			$updated += $this->checkChunk( range( $pageId, $max ) );
		}
		return $updated;
	}

	/**
	 * @param int[] $ids
	 * @return int number of pages corrected
	 */
	private function checkChunk( array $ids ) {
		$updated = $this->checker->check( $ids );
		$this->output( sprintf( "[%20s]%10d/%d\n", wfWikiID(), end( $ids ),
			$this->toId ) );
		return $updated;
	}

	private function setFromAndTo() {
		$dbr = $this->getDB( DB_SLAVE );
		$this->fromId = $this->getOption( 'fromId' );
		if ( $this->fromId === null ) {
			$this->fromId = 0;
		}
		$this->toId = $this->getOption( 'toId' );
		if ( $this->toId === null ) {
			$this->toId = $dbr->selectField( 'page', 'MAX(page_id)' );
			if ( $this->toId === false ) {
				$this->toId = 0;
			} else {
				// Its technically possible for there to be pages in the index with ids greater
				// than the maximum id in the database.  That isn't super likely, but we'll
				// check a bit ahead just in case.  This isn't scientific or super accurate,
				// but its cheap.
				$this->toId += 100;
			}
		}
	}

	private function buildChecker() {
		if ( $this->getOption( 'noop' ) ) {
			$remediator = new NoopRemediator();
		} else {
			$remediator = new QueueingRemediator( $this->getOption( 'cluster' ) );
		}
		if ( !$this->isQuiet() ) {
			$remediator = new PrintingRemediator( $remediator );
		}
		// This searcher searches all indexes for the current wiki.
		$searcher = new Searcher( $this->getConnection(), 0, 0, null, array(), null );
		$this->checker = new Checker(
			$this->getConnection(),
			$remediator,
			$searcher,
			$this->getOption( 'logSane' )
		);
	}
}

$maintClass = "CirrusSearch\Saneitize";
require_once RUN_MAINTENANCE_IF_MAIN;
