<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\SearchConfig;

/**
 * Copy search index from one cluster to another.
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
class CopySearchIndex extends Maintenance {
	/**
	 * @var string
	 */
	private $indexSuffix;

	/**
	 * @var string
	 */
	private $indexBaseName;

	/**
	 * @var int
	 */
	protected $refreshInterval;

	public function __construct() {
		parent::__construct();
		$this->addDescription( "Copy index from one cluster to another.\n" .
			"The index name and index type should be the same on both clusters." );
		$this->addOption( 'indexSuffix', 'Source index.  Either content or general.', true, true );
		$this->addOption( 'targetCluster', 'Target Cluster.', true, true );
		$this->addOption( 'reindexChunkSize', 'Documents per shard to reindex in a batch.   ' .
			'Note when changing the number of shards that the old shard size is used, not the new ' .
			'one.  If you see many errors submitting documents in bulk but the automatic retry as ' .
			'singles works then lower this number.  Defaults to 100.', false, true );
		$this->addOption( 'reindexSlices', 'Number of pieces to slice the scan into, roughly ' .
			'equivilent to concurrency. Defaults to the number of shards', false, true );
	}

	/** @inheritDoc */
	public function execute() {
		$this->indexSuffix = $this->getOption( 'indexSuffix' );
		$this->indexBaseName = $this->getOption( 'baseName',
			$this->getSearchConfig()->get( SearchConfig::INDEX_BASE_NAME ) );

		$reindexChunkSize = $this->getOption( 'reindexChunkSize', 100 );
		$targetCluster = $this->getOption( 'targetCluster' );
		$slices = $this->getOption( 'reindexSlices' );

		$sourceConnection = $this->getConnection();
		$targetConnection = $this->getConnection( $targetCluster );

		if ( $sourceConnection->getClusterName() == $targetConnection->getClusterName() ) {
			$this->fatalError( 'Target cluster should be different from current cluster.' );
		}

		$targetIndexName = $targetConnection->getIndexName( $this->indexBaseName, $this->indexSuffix );
		$utils = new ConfigUtils( $targetConnection->getClient(), $this );
		$indexIdentifier = $this->unwrap( $utils->pickIndexIdentifierFromOption(
			$this->getOption( 'indexIdentifier', 'current' ),
			$targetIndexName
		) );

		$reindexer = new Reindexer(
			$this->getSearchConfig(),
			$sourceConnection,
			$targetConnection,
			// Target Index
			$targetConnection->getIndex( $this->indexBaseName, $this->indexSuffix, $indexIdentifier ),
			// Source Index
			$this->getConnection()->getIndex( $this->indexBaseName, $this->indexSuffix ), $this, [], []
		);
		$reindexer->reindex( $slices, 1, $reindexChunkSize );
		$reindexer->waitForGreen();

		return true;
	}
}

// @codeCoverageIgnoreStart
$maintClass = CopySearchIndex::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
