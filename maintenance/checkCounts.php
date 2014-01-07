<?php

namespace CirrusSearch;
use \Maintenance;
use \SiteStats;

/**
 * Check the number of documents in the search index against the number of pages
 * in SiteStats.
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

class CheckCounts extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = "Check count of documents in search index against count in SiteStats.";
	}

	public function execute() {
		$siteStats = SiteStats::pages();
		$elasticsearch = Connection::getPageType( wfWikiId() )->count();
		$difference = round( 200.0 * abs( $siteStats - $elasticsearch ) / ( $siteStats + $elasticsearch ) );
		$this->output( "SiteStats=$siteStats\n" );
		$this->output( "Elasticsearch=$elasticsearch\n" );
		$this->output( "Percentage=$difference%\n");
	}
}

$maintClass = "CirrusSearch\CheckCounts";
require_once RUN_MAINTENANCE_IF_MAIN;
