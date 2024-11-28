<?php

namespace CirrusSearch\Maintenance;

use MediaWiki\Json\FormatJson;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
require_once __DIR__ . '/../includes/Maintenance/Maintenance.php';

/**
 * Reports index aliases that CirrusSearch owns for this wiki.
 *
 * This information can be used as part of a more complete solution to
 * account for the indices that should exist on an elasticsearch cluster.
 * The output here is strictly related to the configuration of CirrusSearch
 * and does not reference state of any live cluster.
 *
 * CirrusSearch almost always refers to indices by alias, the only time
 * when CirrusSearch owns an index without an alias is during index
 * creation and reindexing. A reasonable proxy to detect this would be
 * updates in the last few minutes. If CirrusSearch owns an index but
 * does not have an alias yet it will be under constant indexing load.
 */
class ExpectedIndices extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Report index alias that CirrusSearch owns.' );
		$this->addOption( 'oneline', 'Dont pretty print the output', false, false );
	}

	public function execute() {
		$builder = new ExpectedIndicesBuilder( $this->getSearchConfig() );
		$cluster = $this->getOption( 'cluster', null );
		echo FormatJson::encode(
			$builder->build( true, $cluster ),
			!$this->getOption( 'oneline' )
		), "\n";
	}

}

$maintClass = ExpectedIndices::class;
require_once RUN_MAINTENANCE_IF_MAIN;
