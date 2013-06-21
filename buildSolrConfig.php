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
class BuildSolrConfig extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = "Build a Solr config directory for this wiki";
		$this->addOption( 'where', 'Defaults to /tmp/solrConfig/<pid>', false, true );
	}
	public function execute() {
		$where = $this->getOption( 'where', '/tmp/solrConfig' . getmypid() );
		if ( file_exists( $where ) ) {
			$this->error( "$where already exists so I can't build a new solr config there.", true );
		}
		$this->output( "Building solr config in $where\n" );
		$schemaBuilder = new SchemaBuilder( $where );
		$schemaBuilder->build();
		$solrConfigBuilder = new SolrConfigBuilder( $where );
		$solrConfigBuilder->build();
	}
}

$maintClass = "BuildSolrConfig";
require_once RUN_MAINTENANCE_IF_MAIN;
