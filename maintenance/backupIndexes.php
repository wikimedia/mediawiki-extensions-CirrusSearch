<?php

namespace CirrusSearch;
use \Maintenance;

/**
 * Create snapshots of your indexes
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

class BackupIndexes extends Maintenance {
	/** @var \Elastica\Snapshot */
	private $snapshot;

	/** @var string */
	private $repoName;

	public function __construct() {
		parent::__construct();
		$this->mDescription = "Snapshot and restore indexes using the snapshot/restore feature of Elastic";
		$this->addOption( 'repo', 'Which repository to use.', true, true );
		$this->addOption( 'mode', "One of 'delete', 'list', 'snapshot', default is 'list'", false, true );
		$this->addOption( 'baseName', 'Base name of index, defaults to wiki id.', false, true );
		$this->addOption( 'snapshot', 'Name of the snapshot, otherwise defaults to cirrus-$baseName', false, true );
	}

	public function execute() {
		global $wgCirrusSearchBackup;

		if ( !$wgCirrusSearchBackup ) {
			$this->error( "No backups configured, see \$wgCirrusSearchBackup", 1 );
		}

		$this->repoName = $this->getOption( 'repo' );
		if ( !isset( $wgCirrusSearchBackup[ $this->repoName ] ) ) {
			$this->error( "No such repository '{$this->repoName}'", 1 );
		}

		$this->snapshot = new \Elastica\Snapshot( Connection::getClient() );
		$this->createRepositoryIfMissing( $wgCirrusSearchBackup[ $this->repoName ] );
		switch( $this->getOption( 'mode', 'list' ) ) {
			case 'delete':
				$snapshot = $this->getOption( 'snapshot' );
				if ( !$snapshot ) {
					$this->error( '--mode=delete requires --snapshot to be set', 1 );
				}
				$this->deleteSnapshot( $snapshot, $this->getSnapshots() );
				break;
			case 'snapshot':
				$this->takeSnapshot();
				break;
			case 'list':
			default:
				$this->listSnapshots( $this->getSnapshots() );
				break;
		}
	}

	private function createRepositoryIfMissing( $settings ) {
		try {
			$this->snapshot->getRepository( $this->repoName );
		} catch ( \Elastica\Exception\NotFoundException $e ) {
			$this->output( "Snapshot repository '{$this->repoName}' does not exist, creating..." );
			$type = $settings['type'];
			unset( $settings['type'] );
			$this->snapshot->registerRepository( $this->repoName, $type, $settings );
			$this->output( "done.\n" );
		}
	}

	private function getSnapshots() {
		$snaps = array();
		$snapshots = $this->snapshot->getAllSnapshots( $this->repoName );
		foreach ( $snapshots['snapshots'] as $shot ) {
			$snaps[ $shot['snapshot'] ] = $shot['indices'];
		}
		return $snaps;
	}

	private function deleteSnapshot( $snapshot, $allSnapshots ) {
		$this->output( "Deleting snapshot '$snapshot' from {$this->repoName}..." );

		if ( !isset( $allSnapshots[ $snapshot ] ) ) {
			$this->output( "no such snapshot, skipping.\n" );
			return;
		}

		$this->snapshot->deleteSnapshot( $this->repoName, $snapshot );
		$this->output( "done.\n" );
	}

	private function listSnapshots( $allSnapshots ) {
		if ( !$allSnapshots ) {
			$this->output( "Repository {$this->repoName} has no snapshots yet.\n" );
			return;
		}
		$this->output( "Listing snapshots for {$this->repoName}:\n" );
		foreach ( $allSnapshots as $shot => $indices ) {
			$this->output( "\t" . $shot . "\t" . implode( ',', $indices ) . "\n" );
		}
	}

	private function takeSnapshot() {
		$baseName = $this->getOption( 'baseName', wfWikiId() );
		$snapshot = $this->getOption( 'snapshot', "cirrus-$baseName" );
		$options = array(
			'ignore_unavailable' => true,
			'indices' => $baseName,
		);

		$this->output( "Creating snapshot '$snapshot'..." );
		$this->snapshot->createSnapshot( $this->repoName, $snapshot, $options, true );
		$this->output( "done.\n" );
	}
}

$maintClass = "CirrusSearch\BackupIndexes";
require_once RUN_MAINTENANCE_IF_MAIN;
