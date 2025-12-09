<?php

namespace CirrusSearch\Jenkins;

use CirrusSearch\Maintenance\Maintenance;

/**
 * Calls maintenance scripts properly to get an empty and configured index and
 * anything else required for browser tests to pass.
 *
 * @license GPL-2.0-or-later
 */

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../../..';
}
require_once "$IP/maintenance/Maintenance.php";
require_once __DIR__ . "/../../includes/Maintenance/Maintenance.php";

class CleanSetup extends Maintenance {
	public function execute() {
		$child = $this->createChild( \CirrusSearch\Maintenance\Metastore::class );
		$child->loadParamsAndArgs( null, [ 'upgrade' => true ] );
		$child->execute();
		$child = $this->createChild( \CirrusSearch\Maintenance\UpdateSearchIndexConfig::class );
		$child->loadParamsAndArgs( null, [ 'startOver' => true ] );
		$child->execute();
		$child = $this->createChild( \CirrusSearch\Maintenance\ForceSearchIndex::class );
		$child->loadParamsAndArgs( null, [
			'skipLinks' => true,
			'indexOnSkip' => true,
		] );
		$child->execute();
		$child = $this->createChild( \CirrusSearch\Maintenance\ForceSearchIndex::class );
		$child->loadParamsAndArgs( null, [ 'skipParse' => true ] );
		$child->execute();
	}
}

$maintClass = CleanSetup::class;
require_once RUN_MAINTENANCE_IF_MAIN;
