<?php

/*
	To install you must first install the Solarium plugin and php5-curl.
*/

$dir = dirname(__FILE__);

$wgSolrSearchServers = 'solr1,solr2,solr3,solr4';
$wgSolrSearchMaxRetries = 3;

/*
 * Solr config settings.
 */
/**
 * Timeout before new records are sent to followers and thus available for search.
 */
$wgSolrSearchSoftCommitTimeout = 1000;
/** 
 * Millis before a request is forced to disk.  Higher uses more memory on the leaders and
 * requires a longer period of time be reindexed on a crash but speeds up indexing.
 */
$wgSolrSearchHardCommitTimeout = 120000;
/**
 * Maximum number of updates that have yet to be flushed to disk.  More costs more memory
 * but speeds up indexing.  Flush occurs when either this or timeout above occurs.
 */
$wgSolrSearchHardCommitMaxPendingDocs = 15000;

$wgAutoloadClasses['SolrSearch'] = $dir . '/SolrSearch.body.php';
$wgAutoloadClasses['SolrSearchUpdater'] = $dir . '/SolrSearchUpdater.php';

$wgHooks[ 'ArticleSaveComplete' ][] = 'SolrSearchUpdater::articleSaved';
