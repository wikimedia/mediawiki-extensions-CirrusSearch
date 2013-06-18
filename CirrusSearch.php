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

// Do we want Solr to clean up its caches in an external thread?
$wgCirrusSearchCacheCleanupThread = true;

// Maximum number of whole result sets to cache.  These are big and take up a bunch of space each
// and are mostly used for filter queries.
$wgCirrusSearchFilterCacheSize = 64;

// When new data is exposed to the slave keep this much of the filter cache.
// If you set it in % then caches that are rarely used will be emptied.
$wgCirrusSearchFilterCacheAutowarmCount = '90%';

// Maximum number of limited result sets to cache.  These are small and we can probably afford to
// cache a ton of them.  These are used for caching query results.
$wgCirrusSearchQueryResultCacheSize = 16 * 1024;

// When new data is exposed to the slave keep this much of the query result cache.
// If you set it in % then caches that are rarely used will be emptied.  In this case that'd be
// the a leader node that occasionally services queries.
$wgCirrusSearchQueryResultCacheAutowarmCount = '90%';

// Maximum number of documents to cache.  Used by all queries to turn index results into useful
// results to be returned.  Since we don't store much in our documents we can cache a bunch of
// them with little memory cost.
$wgCirrusSearchDocumentCacheSize = 16 * 1024;

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
$wgHooks['PrefixSearchBackend'][] = 'CirrusSearch::prefixSearch';

/**
 * i18n
 */
$wgExtensionMessagesFiles['CirrusSearch'] = $dir . 'CirrusSearch.i18n.php';
