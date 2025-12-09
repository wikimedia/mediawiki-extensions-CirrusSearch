<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\SearchConfig;

/**
 * Returns zero status if a Cirrus index needs to be built for this wiki.  If
 * Elasticsearch doesn't look to be up it'll wait a minute for it to come up.
 *
 * @license GPL-2.0-or-later
 */

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
require_once __DIR__ . '/../includes/Maintenance/Maintenance.php';
// @codeCoverageIgnoreEnd

class CirrusNeedsToBeBuilt extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( "Update the configuration or contents of all search indices. Always operates on a single cluster." );
	}

	/** @inheritDoc */
	public function execute() {
		$indexPattern = $this->getSearchConfig()->get( SearchConfig::INDEX_BASE_NAME ) . '_*';
		$end = microtime( true ) + 60;
		while ( true ) {
			try {
				$health = new \CirrusSearch\Elastica\Health( $this->getConnection()->getClient(), $indexPattern );
				$status = $health->getStatus();
				$this->output( "Elasticsearch status:  $status\n" );
				if ( $status === 'green' ) {
					break;
				}
			} catch ( \Elastica\Exception\Connection\HttpException $e ) {
				if ( $e->getError() === CURLE_COULDNT_CONNECT ) {
					$this->output( "Elasticsearch not up.\n" );
					$this->getConnection()->destroyClient();
				} else {
					// The two exit code here makes puppet fail with an error.
					$this->fatalError( 'Connection error:  ' . $e->getMessage(), 2 );
				}
			}
			if ( $end < microtime( true ) ) {
				$this->fatalError( 'Elasticsearch was not ready in time.' );
			}
			sleep( 1 );
		}

		foreach ( $this->getConnection()->getAllIndexSuffixes() as $indexSuffix ) {
			try {
				$count = $this->getConnection()
					->getIndex( $this->getSearchConfig()->get( SearchConfig::INDEX_BASE_NAME ), $indexSuffix )
					->count();
			} catch ( \Elastica\Exception\ResponseException ) {
				$this->output( "$indexSuffix doesn't exist.\n" );
				$this->error( "true" );
				return true;
			}
			if ( $indexSuffix === 'content' && $count === 0 ) {
				$this->output( "No pages in the content index.  Indexes were probably wiped.\n" );
				return true;
			}
			$this->output( "Page count in $indexSuffix:  $count\n" );
		}
		// This result in non-zero exit code, which makes puppet decide that it needs to run whatever is gated by this.
		return false;
	}
}

// @codeCoverageIgnoreStart
$maintClass = CirrusNeedsToBeBuilt::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
