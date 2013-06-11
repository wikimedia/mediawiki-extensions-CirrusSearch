<?php

require_once 'Maintenance.php';

/**
 * Force reindexing change to the wiki.
 */
class BuildSolrConfig extends Maintenance {
	var $from;
	var $to;
	var $chunkSize;

	public function __construct() {
		parent::__construct();
		$this->mDescription = "Force indexing some pages";
		$this->addOption( 'from', 'Start date of reindex in YYYY-mm-ddTHH:mm:ssZ (exc.  Defaults to 0 epoch.', false, true );
		$this->addOption( 'to', 'Start date of reindex in YYYY-mm-ddTHH:mm:ssZ.  Defaults to now.', false, true );
		$this->addOption( 'chunkSize', 'Number of articles to update at a time.  Defaults to 50.', false, true );
	}

	public function execute() {
		// 0 is falsy so MWTimestamp makes that `now`.  '00' is epoch 0.
		$this->from = new MWTimestamp( $this->getOption( 'from', '00' )  );
		$this->to = new MWTimestamp( $this->getOption( 'to', false ) );
		$this->chunkSize = $this->getOption( 'chunkSize', 50 );
		$indexed = 0;
		$rate = 'measuring';
		$solrTime = 0;
		$operationStartTime = microtime(true);
		$pages = $this->findUpdates( $this->from, -1, $this->to );
		$fetchTime = microtime(true) - $operationStartTime;
		$size = count( $pages );
		while ($size > 0) {
			$indexed += $size;
			SolrSearchUpdater::updatePages( $pages );
			$rate = round( $indexed / ( microtime(true) - $operationStartTime ) );
			$lastPage = $pages[$size - 1];
			$lastUpdateTime = new MWTimestamp( $lastPage->getRevision()->getTimestamp() );
			$lastUpdateTimeStr = $lastUpdateTime->getTimestamp( TS_ISO_8601 );
			print "Indexed $size pages ending at $lastUpdateTimeStr at $rate pages/second\n";


			$pages = $this->findUpdates( $lastUpdateTime, $lastPage->getId(), $this->to	);
			$size = count( $pages );
		}
		print "Indexed a total of $indexed pages at $rate pages per second ($solrRate per second for solr and $fetchRate per second for DB)\n";
	}

	/**
	 * Find $this->chunkSize pages who's latest revision is after (minUpdate,minId) and before maxUpdate.
	 *
	 * @return an array of the last update timestamp and id that were found
	 */
	private function findUpdates( $minUpdate, $minId, $maxUpdate ) {
		$dbr = $this->getDB( DB_SLAVE );
		$dbr->debug(true);
		$minUpdate = $dbr->addQuotes( $dbr->timestamp( $minUpdate ) );
		$minId = $dbr->addQuotes( $minId );
		$maxUpdate = $dbr->addQuotes( $dbr->timestamp( $maxUpdate ) );
		$res = $dbr->select(
			array( 'page', 'revision' ),
			WikiPage::selectFields(),
				'page_id = rev_page'
				. ' AND rev_id = page_latest'
				. " AND ( ( $minUpdate = rev_timestamp AND $minId < page_id ) OR $minUpdate < rev_timestamp )"
				. " AND rev_timestamp <= $maxUpdate",
			__METHOD__,
			array( 'ORDER BY' => 'rev_timestamp, page_id',
			       'LIMIT' => $this->chunkSize )
		);
		$result = array();
		foreach ( $res as $row ) {
			$result[] = WikiPage::newFromRow( $row );
		}
		return $result;
	}
}

$maintClass = "BuildSolrConfig";
require_once RUN_MAINTENANCE_IF_MAIN;

