<?php

namespace CirrusSearch;
use \Maintenance;

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
if( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once( "$IP/maintenance/Maintenance.php" );

class UpdateVersionIndex extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = "Update and check the CirrusSearch version index.";
		$this->addOption( 'show-all', 'Show all known versions' );
		$this->addOption( 'update', 'Update the version index for this wiki' );
		$this->addOption( 'baseName', 'What basename to use for all indexes, ' .
			'defaults to wiki id', false, true );
		$this->addOption( 'indent', 'String used to indent every line output ' .
			'in this script.', false, true );
	}

	public function execute() {
		$baseName = $this->getOption( 'baseName', wfWikiId() );
		$this->indent = $this->getOption( 'indent', '' );
		if( $this->hasOption( 'show-all' ) ) {
			$this->show();
		} elseif ( $this->hasOption( 'update' ) ) {
			$this->update( $baseName );
		} else {
			$filter = new \Elastica\Filter\BoolOr();
			foreach ( Connection::getAllIndexTypes() as $type ) {
				$term = new \Elastica\Filter\Term();
				$term->setTerm( '_id', Connection::getIndexName( $baseName, $type ) );
				$filter->addFilter( $term );
			}
			$this->show( $filter );
		}
	}

	private function show( $filter = null ) {
		$query = new \Elastica\Query();
		if ( $filter ) {
			$query->setFilter( $filter );
		}
		// WHAT ARE YOU DOING TRACKING MORE THAN 5000 INDEXES?!?
		$query->setSize( 5000 );
		$res = $this->getType()->getIndex()->search( $query );
		foreach( $res as $r ) {
			$data = $r->getData();
			$this->output( "{$this->indent}index name: " . $r->getId() . "\n" .
				"{$this->indent}  analysis version: " .
					"{$data['analysis_maj']}.{$data['analysis_min']}\n" .
				"{$this->indent}  mapping version: " .
					"{$data['mapping_maj']}.{$data['mapping_min']}\n" .
				"{$this->indent}  shards: {$data['shard_count']}\n"
			);
		}
	}

	private function update( $baseName ) {
		global $wgCirrusSearchShardCount;
		$versionType = $this->getType();
		$this->output( "{$this->indent}Updating tracking indexes..." );
		$docs = array();
		list( $aMaj, $aMin ) = explode( '.', \CirrusSearch\Maintenance\AnalysisConfigBuilder::VERSION );
		list( $mMaj, $mMin ) = explode( '.', \CirrusSearch\Maintenance\MappingConfigBuilder::VERSION );
		foreach( Connection::getAllIndexTypes() as $type ) {
			$docs[] = new \Elastica\Document(
				Connection::getIndexName( $baseName, $type ),
				array(
					'analysis_maj' => $aMaj,
					'analysis_min' => $aMin,
					'mapping_maj' => $mMaj,
					'mapping_min' => $mMin,
					'shard_count' => $wgCirrusSearchShardCount[ $type ],
				)
			);
		}
		$versionType->addDocuments( $docs );
		$this->output( "done\n" );
	}

	private function getType() {
		$index = Connection::getIndex( 'mw_cirrus_versions' );
		if ( !$index->exists() ) {
			$this->output( "{$this->indent}Creating tracking index..." );
			$index->create( array( 'number_of_shards' => 1,
				'auto_expand_replicas' => '0-2', ), true );
			$mapping = new \Elastica\Type\Mapping();
			$mapping->setType( $index->getType( 'version' ) );
			$mapping->setProperties( array(
				'analysis_maj' => array( 'type' => 'long', 'include_in_all' => false ),
				'analysis_min' => array( 'type' => 'long', 'include_in_all' => false ),
				'mapping_maj' => array( 'type' => 'long', 'include_in_all' => false ),
				'mapping_min' => array( 'type' => 'long', 'include_in_all' => false ),
				'shard_count' => array( 'type' => 'long', 'include_in_all' => false ),
			) );
			$mapping->send();
			$this->output( "done\n" );
		}

		return $index->getType( 'version' );
	}
}

$maintClass = "CirrusSearch\UpdateVersionIndex";
require_once RUN_MAINTENANCE_IF_MAIN;
