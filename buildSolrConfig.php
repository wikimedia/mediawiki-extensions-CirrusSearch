<?php

require_once( "maintenance/Maintenance.php" );
require_once( __DIR__ . "/config/TypesConfigBuilder.php" );

/**
 * Build a solr config directory.
 */
class BuildSolrConfig extends Maintenance {
	private $where;

	public function __construct() {
		parent::__construct();
		$this->mDescription = "Build a Solr config directory for this wiki";
		$this->addOption( 'where', 'Defaults to /tmp/solrConfig/<pid>', false, true );
	}
	public function execute() {
		$this->where = $this->getOption( 'where', '/tmp/solrConfig' . getmypid() );
		if ( file_exists( $this->where ) ) {
			$this->error( "$this->where already exists so I can't build a new solr config there.", true );
		}
		$this->output( "Building solr config in $this->where\n" );
		wfMkdirParents( $this->where, 0755 );
		$this->buildSchema();
		$this->buildSolrconfig();
	}

	private function buildSchema() {
		$typesBuilder = new TypesConfigBuilder( $this->where );
		$types = preg_replace( '/^/m', "\t", $typesBuilder->build() );
		$wikiId = wfWikiId();
		$content = <<<XML
<?xml version="1.0" encoding="UTF-8" ?>
<schema name="$wikiId" version="1.5">
	<uniqueKey>id</uniqueKey>
	<fields>
		<field name="_version_" type="long" indexed="true" stored="true" required="true" /> <!-- Required for Solr Cloud -->
		<field name="id" type="id" indexed="true" stored="true" required="true" />
		<field name="title" type="text_splitting" indexed="true" stored="true" required="true" />
		<field name="text" type="text_splitting" indexed="true" stored="false" />

		<!-- Power prefix searches -->
		<field name="titlePrefix" type="prefix" indexed="true" stored="false" />
	</fields>
	<copyField source="title" dest="titlePrefix" />
	$types
</schema>
XML;
		file_put_contents( "$this->where/schema.xml", $content );
	}

	private function buildSolrconfig() {
		global $wgCirrusSearchSoftCommitTimeout, $wgCirrusSearchHardCommitTimeout, $wgCirrusSearchHardCommitMaxPendingDocs;
		$content = <<<XML
<?xml version="1.0" encoding="UTF-8" ?>
<config>
	<luceneMatchVersion>LUCENE_43</luceneMatchVersion>
	<updateHandler class="solr.DirectUpdateHandler2">
		<updateLog> <!-- Required for Solr Cloud -->
			<str name="dir">\${solr.data.dir:}</str>
		</updateLog>
		<autoCommit>
			<maxTime>$wgCirrusSearchHardCommitTimeout</maxTime>
			<maxDocs>$wgCirrusSearchHardCommitMaxPendingDocs</maxDocs>
			<openSearcher>false</openSearcher> 
		</autoCommit>
		<autoSoftCommit>
			<maxTime>$wgCirrusSearchSoftCommitTimeout</maxTime>
		</autoSoftCommit>
	</updateHandler>
	<requestDispatcher handleSelect="false" />

	<requestHandler name="/select" class="solr.SearchHandler"> <!-- Serves normal searches -->
		<lst name="defaults">
			<str name="echoParams">explicit</str>
			<int name="rows">10</int>
			<str name="df">text</str>
		</lst>
	</requestHandler>
	<requestHandler name="/get" class="solr.RealTimeGetHandler"> <!-- Required for Solr Cloud -->
		<lst name="defaults">
			<str name="omitHeader">true</str>
		</lst>
	</requestHandler>
	<requestHandler name="/replication" class="solr.ReplicationHandler" startup="lazy" /> <!-- Required for Solr Cloud -->
	<requestHandler name="/admin/" class="solr.admin.AdminHandlers" /> <!-- Required for Solr Cloud but really useful anyway -->
	<requestHandler name="/update" class="solr.UpdateRequestHandler" /> <!-- Required to process updates -->
	<requestHandler name="/admin/ping" class="solr.PingRequestHandler"> <!-- Support ping requests -->
		<lst name="invariants">
			<str name="q">solrpingquery</str>
		</lst>
		<lst name="defaults">
			<str name="echoParams">all</str>
		</lst>
	</requestHandler>

	<requestHandler name="/analysis/field" startup="lazy" class="solr.FieldAnalysisRequestHandler" />
	<requestHandler name="/analysis/document" startup="lazy" class="solr.DocumentAnalysisRequestHandler" />
</config>
XML;
		file_put_contents( "$this->where/solrconfig.xml", $content );
	}


	private function indent( $source ) {
		return preg_replace( '/^/m', "\t", $source );
	}

	private function copyRawConfigFile( $path ) {
		$source = __DIR__ . '/config/copiedRaw/' . $path;
		$dest = $this->where . '/' . $path;
		wfMkdirParents( dirname( $dest ), 0755 );
		copy( $source, $dest );
	}
}

$maintClass = "BuildSolrConfig";
require_once RUN_MAINTENANCE_IF_MAIN;
