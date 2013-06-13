<?php

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
