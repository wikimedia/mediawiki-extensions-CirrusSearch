<?php

namespace CirrusSearch;
use \CirrusSearch\Sanity\Checker;
use \CirrusSearch\Sanity\NoopRemediator;
use \CirrusSearch\Sanity\PrintingRemediator;
use \CirrusSearch\Sanity\QueueingRemediator;
use \Maintenance;

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

class Saneitize extends Maintenance {
	private $fromId;
	private $toId;

	public function __construct() {
		parent::__construct();
		$this->mDescription = "Make the index sane.";
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
		global $wgPoolCounterConf,
			$wgCirrusSearchMaintenanceTimeout,
			$wgCirrusSearchClientSideUpdateTimeout;

		// Set the timeout for maintenance actions
		Connection::setTimeout( $wgCirrusSearchMaintenanceTimeout );
		$wgCirrusSearchClientSideUpdateTimeout = $wgCirrusSearchMaintenanceTimeout;

		// Make sure we don't flood the pool counter
		unset( $wgPoolCounterConf['CirrusSearch-Search'] );

		$this->setFromAndTo();
		$buildChunks = $this->getOption( 'buildChunks');
		if ( $buildChunks ) {
			$builder = new \CirrusSearch\Maintenance\ChunkBuilder();
			$builder->build( $this->mSelf, $this->mOptions, $buildChunks, $this->fromId, $this->toId );
			return;
		}
		$this->buildChecker();
		$this->check();
	}

	private function check() {
		for ( $pageId = $this->fromId; $pageId <= $this->toId; $pageId++ ) {
			$status = $this->checker->check( $pageId );
			if ( !$status->isOK() ) {
				$this->error( $status->getWikiText(), 1 );
			}
			if ( ( $pageId - $this->fromId ) % 100 === 0 ) {
				$this->output( sprintf( "[%20s]%10d/%d\n", wfWikiId(), $pageId,
					$this->toId ) );
			}
		}
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
			$this->remediator = new NoopRemediator();
		} else {
			$this->remediator = new QueueingRemediator();
		}
		if ( !$this->isQuiet() ) {
			$this->remediator = new PrintingRemediator( $this->remediator );
		}
		// This searcher searches all indexes for the current wiki.
		$searcher = new Searcher( 0, 0, false, null );
		$this->checker = new Checker( $this->remediator, $searcher, $this->getOption( 'logSane' ) );
	}
}

$maintClass = "CirrusSearch\Saneitize";
require_once RUN_MAINTENANCE_IF_MAIN;
