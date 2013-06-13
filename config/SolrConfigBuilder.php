<?php
/**
 * Builds solrconfig.xml.
 */
class SolrConfigBuilder extends ConfigBuilder {
	public function __construct($where) {
		parent::__construct($where);
	}

	public function build() {
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
		$this->writeConfigFile( "solrconfig.xml", $content );
	}
}
