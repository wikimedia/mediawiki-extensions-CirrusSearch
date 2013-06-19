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
		global $wgCirrusSearchCacheCleanupThread;
		global $wgCirrusSearchFilterCacheSize, $wgCirrusSearchFilterCacheAutowarmCount;
		global $wgCirrusSearchQueryResultCacheSize, $wgCirrusSearchQueryResultCacheAutowarmCount;
		global $wgCirrusSearchDocumentCacheSize;
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
			<!-- Defaults for spell checking.
				If you want spell checking then turn it on with spellcheck=true in the query. -->
			<str name="spellcheck.dictionary">default</str>
			<str name="spellcheck.dictionary">wordbreak</str>
			<str name="spellcheck.extendedResults">true</str>
			<str name="spellcheck.count">10</str>
			<str name="spellcheck.alternativeTermCount">5</str>
			<str name="spellcheck.maxResultsForSuggest">5</str>
			<str name="spellcheck.collate">true</str>
			<str name="spellcheck.collateExtendedResults">true</str>
			<str name="spellcheck.maxCollationTries">10</str>
			<str name="spellcheck.maxCollations">1</str>
			<!-- Defaults for highlighting.
				If you want highlighting then turn it on with hl=true in the query. -->
			<str name="hl.fl">title,text</str>
			<int name="hl.snippets">1</int>
			<str name="hl.simple.pre">&lt;span class=&quot;searchmatch&quot;&gt;</str>
			<str name="hl.simple.post">&lt;/span&gt;</str>
			<int name="hl.maxAnalyzedChars">100000</int>
		</lst>
		<arr name="last-components">
			<str>spellcheck</str>
		</arr>
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

	<searchComponent name="spellcheck" class="solr.SpellCheckComponent">
		<str name="queryAnalyzerFieldType">spell</str>
		<lst name="spellchecker">
			<str name="name">default</str>
			<str name="field">allText</str>
			<str name="classname">solr.DirectSolrSpellChecker</str>
			<float name="accuracy">0.5</float>
			<int name="maxEdits">2</int>
			<int name="minPrefix">1</int>
			<int name="maxInspections">5</int>
			<int name="minQueryLength">4</int>
			<float name="maxQueryFrequency">0.01</float>
		</lst>
		
		<lst name="spellchecker">
			<str name="name">wordbreak</str>
			<str name="classname">solr.WordBreakSolrSpellChecker</str>      
			<str name="field">allText</str>
			<str name="combineWords">true</str>
			<str name="breakWords">true</str>
			<int name="maxChanges">10</int>
		</lst>
	</searchComponent>

	<query>
		<filterCache class="solr.FastLRUCache"
			size="$wgCirrusSearchFilterCacheSize"
			initialSize="$wgCirrusSearchFilterCacheSize"
			autowarmCount="$wgCirrusSearchFilterCacheAutowarmCount"
			cleanupThread="$wgCirrusSearchCacheCleanupThread" />
		<queryResultCache class="solr.FastLRUCache"
			size="$wgCirrusSearchQueryResultCacheSize"
			initialSize="$wgCirrusSearchQueryResultCacheSize"
			autowarmCount="$wgCirrusSearchQueryResultCacheAutowarmCount"
			cleanupThread="$wgCirrusSearchCacheCleanupThread" />
		<documentCache class="solr.FastLRUCache"
			size="$wgCirrusSearchDocumentCacheSize"
			initialSize="$wgCirrusSearchDocumentCacheSize"
			cleanupThread="$wgCirrusSearchCacheCleanupThread" />
	</query>
</config>
XML;
		$this->writeConfigFile( "solrconfig.xml", $content );
	}
}
