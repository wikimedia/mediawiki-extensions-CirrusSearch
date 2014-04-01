<?php

namespace CirrusSearch;
use \Maintenance;

/**
 * Create backups of your indexes
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

	public function __construct() {
		parent::__construct();
		$this->mDescription = "Backup and restore indexes using the snapshot/restore feature of Elastic";
		$this->addOption( 'all', 'Backup all indexes, not just the one for this wiki' );
		$this->addOption( 'baseName', 'Base name of index, defaults to wiki id. Cannot be used with --all', false, true );
		$this->addOption( 'backupName', 'Name of the backup, otherwise defaults to cirrus-$timestamp', false, true );
		$this->addOption( 'list', 'List existing backups' );
	}

	public function execute() {
		global $wgCirrusSearchBackup;

		if ( !$wgCirrusSearchBackup ) {
			$this->output( "No backups configured, see \$wgCirrusSearchBackup\n" );
			return;
		}

		$this->snapshot = new \Elastica\Snapshot( Connection::getClient() );
		foreach ( $wgCirrusSearchBackup as $name => $settings ) {
			$this->createRepositoryIfMissing( $name, $settings );
			if ( $this->hasOption( 'list' ) ) {
				$this->listBackups( $name );
			} else {
				$this->backup( $name );
			}
		}
	}

	private function createRepositoryIfMissing( $name, $settings ) {
		try {
			$this->snapshot->getRepository( $name );
		} catch ( \Elastica\Exception\NotFoundException $e ) {
			$this->output( "Backup repo '$name' does not exist, creating..." );
			$type = $settings['type'];
			unset( $settings['type'] );
			$this->snapshot->registerRepository( $name, $type, $settings );
			$this->output( "done.\n" );
		}
	}

	private function listBackups( $name ) {
		$snapshots = $this->snapshot->getAllSnapshots( $name );
		foreach ( $snapshots['snapshots'] as $shot ) {
			$this->output( $shot['snapshot'] . "\t" . implode( ',', $shot['indices'] ) . "\n" );
		}
	}

	private function backup( $name ) {
		$backupName = $this->getOption( 'backupName', 'cirrus-' . time() );
		$options = array(
			'ignore_unavailable' => true,
		);
		if ( !$this->hasOption( 'all' ) ) {
			$options['indices'] = $this->getOption( 'baseName', wfWikiId() );
		}
		$this->output( "Creating snapshot '$backupName'..." );
		$this->snapshot->createSnapshot( $name, $backupName, $options, true );
		$this->output( "done.\n" );
	}
}

$maintClass = "CirrusSearch\BackupIndexes";
require_once RUN_MAINTENANCE_IF_MAIN;
