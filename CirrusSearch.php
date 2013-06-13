<?php

/**
 * CirrusSearch - Searching for MediaWiki with Solr
 * 
 * Requires Solarium extension installed (provides solarium library)
 * Requires cURL support for PHP (php5-curl package)
 * Set $wgSearchType to 'SearchSolr'
 */

$wgExtensionCredits['other'][] = array(
	'path'           => __FILE__,
	'name'           => 'CirrusSearch',
	'author'         => array( 'Nik Everett', 'Chad Horohoe' ),
	'descriptionmsg' => 'cirrussearch-desc',
	'url'            => 'https://www.mediawiki.org/wiki/Extension:MWSearch',
	'version'        => '0.1'
);

/**
 * Configuration
 */

// Solr servers
$wgCirrusSearchServers = array( 'solr1', 'solr2', 'solr3', 'solr4' );

// Maximum times to retry on failure
$wgCirrusSearchMaxRetries = 3;

// Timeout before new records are sent to followers and thus available for search.
$wgCirrusSearchSoftCommitTimeout = 1000;

// Millis before a request is forced to disk.  Higher uses more memory on the leaders and
// requires a longer period of time be reindexed on a crash but speeds up indexing.
$wgCirrusSearchHardCommitTimeout = 120000;

// Maximum number of updates that have yet to be flushed to disk.  More costs more memory
// but speeds up indexing.  Flush occurs when either this or timeout above occurs.
$wgCirrusSearchHardCommitMaxPendingDocs = 15000;

// How long to cache search results in memcached, if at all (in seconds)
$wgCirrusSearchCacheResultTime = 0;

// Do we update the search index after a change in the process that changed it?
$wgCirrusSearchUpdateInProcess = true;


$dir = __DIR__ . '/';
/**
 * Classes
 */
$wgAutoloadClasses['CirrusSearch'] = $dir . 'CirrusSearch.body.php';
$wgAutoloadClasses['CirrusSearchUpdater'] = $dir . 'CirrusSearchUpdater.php';
$wgAutoloadClasses['ConfigBuilder'] = $dir . 'config/ConfigBuilder.php';
$wgAutoloadClasses['SchemaBuilder'] = $dir . 'config/SchemaBuilder.php';
$wgAutoloadClasses['SolrConfigBuilder'] = $dir . 'config/SolrConfigBuilder.php';
$wgAutoloadClasses['TypesBuilder'] = $dir . 'config/TypesBuilder.php';

/**
 * Hooks
 */
$wgHooks['ArticleDeleteComplete'][] = 'CirrusSearchUpdater::articleDeleted';
$wgHooks['ArticleSaveComplete'][] = 'CirrusSearchUpdater::articleSaved';
$wgHooks['TitleMoveComplete'][] = 'CirrusSearchUpdater::articleMoved';
$wgHooks['PrefixSearchBackend'][] = 'CirrusSearch::prefixSearch';

/**
 * i18n
 */
$wgExtensionMessagesFiles['CirrusSearch'] = $dir . 'CirrusSearch.i18n.php';
