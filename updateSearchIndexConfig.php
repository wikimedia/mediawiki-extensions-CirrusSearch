<?php
/**
 * Update the search configuration on the search backend.
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
	$IP = __DIR__ . '/../..';
}
require_once( "$IP/maintenance/Maintenance.php" );
/**
 * Update the elasticsearch configuration for this index.
 */
class UpdateSearchIndexConfig extends Maintenance {
	private $returnCode = 0;

	public function __construct() {
		parent::__construct();
		$this->addDescription( "Update the configuration or contents of all search indecies." );
		// Directly require this script so we can include its parameters as maintenance scripts can't use the autoloader
		// in __construct.  Lame.
		require_once __DIR__ . '/updateOneSearchIndexConfig.php';
		UpdateOneSearchIndexConfig::addSharedOptions( $this );
	}

	public function execute() {
		$this->output( CirrusSearch::CONTENT_INDEX_TYPE . " index...\n");
		$child = $this->runChild( 'UpdateOneSearchIndexConfig' );
		$child->mOptions[ 'indexType' ] = CirrusSearch::CONTENT_INDEX_TYPE;
		$child->mOptions[ 'indent' ] = "\t";
		$child->execute();

		$this->output( CirrusSearch::GENERAL_INDEX_TYPE . " index...\n");
		$child = $this->runChild( 'UpdateOneSearchIndexConfig' );
		$child->mOptions[ 'indexType' ] = CirrusSearch::GENERAL_INDEX_TYPE;
		$child->mOptions[ 'indent' ] = "\t";
		$child->execute();
	}
}

$maintClass = "UpdateSearchIndexConfig";
require_once RUN_MAINTENANCE_IF_MAIN;
