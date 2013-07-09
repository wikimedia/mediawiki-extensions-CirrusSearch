<?php
/**
 * Generate the Solr configuration
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
require_once( "maintenance/Maintenance.php" );

/**
 * Build a solr config directory.
 */
class UpdateElasticsearchIndex extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = "Update the elasticsearch index for this wiki";
		$this->addOption( 'rebuild', 'Rebuild the index' );
	}
	public function execute() {
		global $wgCirrusSearchShardCount, $wgCirrusSearchReplicatCount;
		$rebuild = $this->getOption( 'rebuild', false );
		if ( $rebuild ) {
			$this->output( "Rebuilding index\n" );
			$rebuild = true;
		} else {
			$this->output( "Createing index\n" );
			// TODO update the index if it already exists/warn user about what can't be updated.
		}
		CirrusSearch::getIndex()->create( array(
			'number_of_shards' => $wgCirrusSearchShardCount,
        	'number_of_replicas' => $wgCirrusSearchReplicatCount
		), $rebuild );
		// TODO build the analyzers and mappings
		// $schemaBuilder = new SchemaBuilder( $where );
		// $schemaBuilder->build();
		// $solrConfigBuilder = new SolrConfigBuilder( $where );
		// $solrConfigBuilder->build();
	}
}

$maintClass = "UpdateElasticsearchIndex";
require_once RUN_MAINTENANCE_IF_MAIN;
