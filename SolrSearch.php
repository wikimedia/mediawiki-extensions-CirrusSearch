<?php

/**
 * SolrSearch - Searching for MediaWiki with Solr
 * 
 * Requires Solarium extension installed (provides solarium library)
 * Requires cURL support for PHP (php5-curl package)
 * Set $wgSearchType to 'SearchSolr'
 */

$wgExtensionCredits['other'][] = array(
	'path'           => __FILE__,
	'name'           => 'SolrSearch',
	'author'         => array( 'Nik Everett', 'Chad Horohoe' ),
	'descriptionmsg' => 'solrsearch-desc',
	'url'            => 'https://www.mediawiki.org/wiki/Extension:MWSearch',
);

/**
 * Configuration
 */

// Solr servers
$wgSolrSearchServers = array( 'solr1', 'solr2', 'solr3', 'solr4' );

// Maximum times to retry on failure
$wgSolrSearchMaxRetries = 3;

// Timeout before new records are sent to followers and thus available for search.
$wgSolrSearchSoftCommitTimeout = 1000;

// Millis before a request is forced to disk.  Higher uses more memory on the leaders and
// requires a longer period of time be reindexed on a crash but speeds up indexing.
$wgSolrSearchHardCommitTimeout = 120000;

// Maximum number of updates that have yet to be flushed to disk.  More costs more memory
// but speeds up indexing.  Flush occurs when either this or timeout above occurs.
$wgSolrSearchHardCommitMaxPendingDocs = 15000;

// How long to cache search results in memcached, if at all (in seconds)
$wgSolrSearchCacheResultTime = 0;

// Do we update the search index after a change in the process that changed it?
$wgSolrSearchUpdateInProcess = true;


$dir = __DIR__ . '/';
/**
 * Classes
 */
$wgAutoloadClasses['SolrSearch'] = $dir . 'SolrSearch.body.php';
$wgAutoloadClasses['SolrSearchUpdater'] = $dir . 'SolrSearchUpdater.php';

/**
 * Hooks
 */
$wgHooks['ArticleDeleteComplete'][] = 'SolrSearchUpdater::articleDeleted';
$wgHooks['ArticleSaveComplete'][] = 'SolrSearchUpdater::articleSaved';
$wgHooks['TitleMoveComplete'][] = 'SolrSearchUpdater::articleMoved';
$wgHooks['PrefixSearchBackend'][] = 'SolrSearch::prefixSearch';

/**
 * i18n
 */
$wgExtensionMessagesFiles['SolrSearch'] = $dir . 'SolrSearch.i18n.php';
