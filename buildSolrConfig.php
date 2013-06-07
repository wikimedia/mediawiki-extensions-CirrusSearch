<?php
/**
 * Build a solr config directory.
 */

require_once 'Maintenance.php';

/**
 * Maintenance script to wait until slave lag goes under a certain value.
 *
 * @ingroup Maintenance
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
			error_log( "$this->where already exists so I can't build a new solr config there." );
			return 1;
		}
		print "Building solr config in $this->where\n";
		mkdir( $this->where, 0755, true );
		$this->buildSchema();
		$this->buildSolrconfig();
		$this->copyRawConfig();
	}

	private function buildSchema() {
		global $wgSitename;
		$types = preg_replace( '/^/m', "\t", file_get_contents( __DIR__ . '/config/types.xml' ) );
		$content = <<<END
<?xml version="1.0" encoding="UTF-8" ?>
<schema name="$wgSitename" version="1.5">
	<uniqueKey>id</uniqueKey>
	<fields>
		<field name="_version_" type="long" indexed="true" stored="true" required="true" /> <!-- Required for Solr Cloud -->
		<field name="id" type="long" indexed="true" stored="true" required="true" />
		<field name="title" type="text_en_splitting" indexed="true" stored="false" required="true" />
		<field name="text" type="text_en_splitting" indexed="true" stored="false" />
	</fields>
	$types
</schema>
END;
		file_put_contents( "$this->where/schema.xml", $content );
	}

	private function buildSolrconfig() {
		global $wgSolrSearchSoftCommitTimeout, $wgSolrSearchHardCommitTimeout, $wgSolrSearchHardCommitMaxPendingDocs;
		$content = <<<END
<?xml version="1.0" encoding="UTF-8" ?>
<config>
	<luceneMatchVersion>LUCENE_43</luceneMatchVersion>
	<updateHandler class="solr.DirectUpdateHandler2">
		<updateLog> <!-- Required for Solr Cloud -->
			<str name="dir">\${solr.data.dir:}</str>
		</updateLog>
		<autoCommit> 
			<maxTime>$wgSolrSearchHardCommitTimeout</maxTime>
			<maxDocs>$wgSolrSearchHardCommitMaxPendingDocs</maxDocs>
			<openSearcher>false</openSearcher> 
		</autoCommit>
		<autoSoftCommit>
			<maxTime>$wgSolrSearchSoftCommitTimeout</maxTime>
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
</config>
END;
		file_put_contents( "$this->where/solrconfig.xml", $content );
	}

	private function copyRawConfig() {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( __DIR__ . '/config/copiedRaw/', RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $iterator as $node ) {
		  if ( $node->isDir() ) {
			mkdir( $this->where . '/' . $iterator->getSubPathName() );
		  } else {
			copy( $node, $this->where . '/' . $iterator->getSubPathName() );
		  }
		}
	}
}

$maintClass = "BuildSolrConfig";
require_once RUN_MAINTENANCE_IF_MAIN;
