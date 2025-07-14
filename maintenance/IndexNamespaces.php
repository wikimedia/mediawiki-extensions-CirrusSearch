<?php

namespace CirrusSearch\Maintenance;

use MediaWiki\MediaWikiServices;

/**
 * Index all namespaces for quick lookup.
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
