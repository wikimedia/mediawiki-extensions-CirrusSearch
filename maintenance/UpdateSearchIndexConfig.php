<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\Connection;

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

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
require_once __DIR__ . '/../includes/Maintenance/Maintenance.php';
// @codeCoverageIgnoreEnd

/**
 * Update the elasticsearch configuration for this index.
 */
class UpdateSearchIndexConfig extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( "Update the configuration or contents of all search indices. This always operates on a single cluster." );
		// Directly require this script so we can include its parameters as maintenance scripts can't use the autoloader
		// in __construct.  Lame.
		require_once __DIR__ . '/UpdateOneSearchIndexConfig.php';
		UpdateOneSearchIndexConfig::addSharedOptions( $this );
	}

	/**
	 * @return bool|null
	 * @suppress PhanUndeclaredMethod runChild technically returns a
	 *  \Maintenance instance but only \CirrusSearch\Maintenance\Maintenance
	 *  classes have the done method. Just allow it since we know what type of
	 *  maint class is being created
	 */
	public function execute() {
		foreach ( $this->clustersToWriteTo() as $cluster ) {
			$this->outputIndented( "Updating cluster $cluster...\n" );

			$this->outputIndented( "indexing namespaces...\n" );
			$child = $this->runChild( IndexNamespaces::class );
			$child->done();
			$child->loadParamsAndArgs(
				null,
				array_merge( $this->parameters->getOptions(), [
					'cluster' => $cluster,
				] ),
				$this->parameters->getArgs()
			);
			$child->execute();
			$child->done();

			$conn = Connection::getPool( $this->getSearchConfig(), $cluster );
			foreach ( $conn->getAllIndexSuffixes( null ) as $indexSuffix ) {
				$this->outputIndented( "$indexSuffix index...\n" );
				$child = $this->createChild( UpdateOneSearchIndexConfig::class );
				$child->done();
				$child->loadParamsAndArgs(
					null,
					array_merge( $this->parameters->getOptions(), [
						'cluster' => $cluster,
						'indexSuffix' => $indexSuffix,
					] ),
					$this->parameters->getArgs()
				);
				$child->execute();
				$child->done();
			}
		}

		return true;
	}

	/**
	 * Convenience method to interperet the 'all' cluster
	 * as a request to run against each of the known clusters.
	 *
	 * @return string[]
	 */
	protected function clustersToWriteTo() {
		$cluster = $this->getOption( 'cluster', null );
		if ( $cluster === 'all' ) {
			return $this->getSearchConfig()
				->getClusterAssignment()
				->getManagedClusters();
		} else {
			// single specified cluster. May be null, which
			// indirectly selects the default search cluster.
			return [ $cluster ];
		}
	}

}

// @codeCoverageIgnoreStart
$maintClass = UpdateSearchIndexConfig::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
