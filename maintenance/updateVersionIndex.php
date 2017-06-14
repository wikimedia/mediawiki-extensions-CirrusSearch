<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\SearchConfig;

/**
 * Update and check the CirrusSearch version index.
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
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
require_once __DIR__ . '/../includes/Maintenance/Maintenance.php';

class UpdateVersionIndex extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = "*** DEPRECATED *** use metastore.php.";
		$this->addOption( 'show-all', 'Show all known versions' );
		$this->addOption( 'update', 'Update the version index for this wiki' );
		$this->addOption( 'baseName', 'What basename to use for all indexes, ' .
			'defaults to wiki id', false, true );
	}

	/**
	 * @suppress PhanAccessPropertyProtected Phan has a bug where it thinks we can't
	 *  access mOptions because its protected. That would be true but this
	 *  class shares the hierarchy that contains mOptions so php allows it.
	 * @suppress PhanUndeclaredMethod runChild technically returns a
	 *  \Maintenance instance but only \CirrusSearch\Maintenance\Maintenance
	 *  classes have the done method. Just allow it since we know what type of
	 *  maint class is being created
	 */
	public function execute() {
		$baseName = $this->getOption( 'baseName', $this->getSearchConfig()->get( SearchConfig::INDEX_BASE_NAME ) );
		if ( $this->hasOption( 'show-all' ) ) {
			$this->output( "*** updateVersionIndex.php is deprecated use metastore.php --show-all-index-versions instead.\n" );
			$child = $this->runChild( Metastore::class );
			$child->mOptions[ 'show-all-index-versions' ] = true;
			$child->mOptions[ 'index-version-basename' ] = $baseName;
			$child->execute();
			$child->done();
		} elseif ( $this->hasOption( 'update' ) ) {
			$this->output( "*** updateVersionIndex.php is deprecated use metastore.php --update-index-version instead.\n" );
			$child = $this->runChild( Metastore::class );
			$child->mOptions[ 'update-index-version' ] = true;
			$child->mOptions[ 'index-version-basename' ] = $baseName;
			$child->execute();
			$child->done();
		} else {
			$this->output( "*** updateVersionIndex.php is deprecated use metastore.php --show-index-version instead.\n" );
			$child = $this->runChild( Metastore::class );
			$child->mOptions[ 'show-index-version' ] = true;
			$child->mOptions[ 'index-version-basename' ] = $baseName;
			$child->execute();
			$child->done();
		}
	}
}

$maintClass = UpdateVersionIndex::class;
require_once RUN_MAINTENANCE_IF_MAIN;
