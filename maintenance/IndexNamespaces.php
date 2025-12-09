<?php

namespace CirrusSearch\Maintenance;

use MediaWiki\MediaWikiServices;

/**
 * Index all namespaces for quick lookup.
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

class IndexNamespaces extends Maintenance {

	/** @inheritDoc */
	public function execute() {
		// We allow automatic creation, rather than requiring pre-existance like
		// other scripts, to make initial setup simple and straight forward. In
		// most fresh installations the metastore is first created here when invoked
		// by UpdateSearchIndexConfig.
		$store = $this->maybeCreateMetastore()->namespaceStore();
		$this->outputIndented( "Indexing namespaces..." );
		$store->reindex( MediaWikiServices::getInstance()->getContentLanguage() );
		$this->output( "done\n" );

		return true;
	}
}

// @codeCoverageIgnoreStart
$maintClass = IndexNamespaces::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
