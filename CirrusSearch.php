<?php

/**
 * CirrusSearch - Searching for MediaWiki with Solr
 * 
 * Requires Solarium extension installed (provides solarium library)
 * Requires cURL support for PHP (php5-curl package)
 * Set $wgSearchType to 'SearchSolr'
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

$wgExtensionCredits['other'][] = array(
	'path'           => __FILE__,
	'name'           => 'CirrusSearch',
	'author'         => array( 'Nik Everett', 'Chad Horohoe' ),
	'descriptionmsg' => 'cirrussearch-desc',
	'url'            => 'https://www.mediawiki.org/wiki/Extension:CirrusSearch',
	'version'        => '0.1'
);

/**
 * Configuration
 */

// Solr servers
$wgCirrusSearchServers = array( 'elasticsearch1', 'elasticsearch2', 'elasticsearch3', 'elasticsearch4' );

// Number of shards
$wgCirrusSearchShardCount = 4;

// Number of replicas per shard
$wgCirrusSearchReplicaCount = 1;

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
$elasticaDir = $dir . 'Elastica/lib/Elastica/';
/**
 * Classes
 */
$wgAutoloadClasses['CirrusSearch'] = $dir . 'CirrusSearch.body.php';
$wgAutoloadClasses['CirrusSearchUpdater'] = $dir . 'CirrusSearchUpdater.php';
$wgAutoloadClasses['ConfigBuilder'] = $dir . 'config/ConfigBuilder.php';
$wgAutoloadClasses['SchemaBuilder'] = $dir . 'config/SchemaBuilder.php';
$wgAutoloadClasses['SolrConfigBuilder'] = $dir . 'config/SolrConfigBuilder.php';
$wgAutoloadClasses['TypesBuilder'] = $dir . 'config/TypesBuilder.php';
$wgAutoloadClasses['Elastica\Bulk'] = $elasticaDir . 'Bulk.php';
$wgAutoloadClasses['Elastica\Client'] = $elasticaDir . 'Client.php';
$wgAutoloadClasses['Elastica\Connection'] = $elasticaDir . 'Connection.php';
$wgAutoloadClasses['Elastica\Document'] = $elasticaDir . 'Document.php';
$wgAutoloadClasses['Elastica\Index'] = $elasticaDir . 'Index.php';
$wgAutoloadClasses['Elastica\Param'] = $elasticaDir . 'Param.php';
$wgAutoloadClasses['Elastica\Query'] = $elasticaDir . 'Query.php';
$wgAutoloadClasses['Elastica\Request'] = $elasticaDir . 'Request.php';
$wgAutoloadClasses['Elastica\Response'] = $elasticaDir . 'Response.php';
$wgAutoloadClasses['Elastica\Result'] = $elasticaDir . 'Result.php';
$wgAutoloadClasses['Elastica\ResultSet'] = $elasticaDir . 'ResultSet.php';
$wgAutoloadClasses['Elastica\Search'] = $elasticaDir . 'Search.php';
$wgAutoloadClasses['Elastica\SearchableInterface'] = $elasticaDir . 'SearchableInterface.php';
$wgAutoloadClasses['Elastica\Type'] = $elasticaDir . 'Type.php';
$wgAutoloadClasses['Elastica\Util'] = $elasticaDir . 'Util.php';
$wgAutoloadClasses['Elastica\Bulk\Action'] = $elasticaDir . 'Bulk/Action.php';
$wgAutoloadClasses['Elastica\Bulk\Action\AbstractDocument'] = $elasticaDir . 'Bulk/Action/AbstractDocument.php';
$wgAutoloadClasses['Elastica\Bulk\Action\IndexDocument'] = $elasticaDir . 'Bulk/Action/IndexDocument.php';
$wgAutoloadClasses['Elastica\Bulk\Response'] = $elasticaDir . 'Bulk/Response.php';
$wgAutoloadClasses['Elastica\Bulk\ResponseSet'] = $elasticaDir . 'Bulk/ResponseSet.php';
$wgAutoloadClasses['Elastica\Exception\BulkException'] = $elasticaDir . 'Exception/BulkException.php';
$wgAutoloadClasses['Elastica\Exception\ExceptionInterface'] = $elasticaDir . 'Exception/ExceptionInterface.php';
$wgAutoloadClasses['Elastica\Exception\InvalidException'] = $elasticaDir . 'Exception/InvalidException.php';
$wgAutoloadClasses['Elastica\Exception\NotFoundException'] = $elasticaDir . 'Exception/NotFoundException.php';
$wgAutoloadClasses['Elastica\Exception\ResponseException'] = $elasticaDir . 'Exception/ResponseException.php';
$wgAutoloadClasses['Elastica\Exception\Bulk\ResponseException'] = $elasticaDir . 'Exception/Bulk/ResponseException.php';
$wgAutoloadClasses['Elastica\Exception\Bulk\Response\ActionException'] = $elasticaDir . 'Exception/Bulk/Response/ActionException.php';
$wgAutoloadClasses['Elastica\Filter\AbstractFilter'] = $elasticaDir . 'Filter/AbstractFilter.php';
$wgAutoloadClasses['Elastica\Filter\AbstractMulti'] = $elasticaDir . 'Filter/AbstractMulti.php';
$wgAutoloadClasses['Elastica\Filter\Bool'] = $elasticaDir . 'Filter/Bool.php';
$wgAutoloadClasses['Elastica\Filter\Prefix'] = $elasticaDir . 'Filter/Prefix.php';
$wgAutoloadClasses['Elastica\Filter\Query'] = $elasticaDir . 'Filter/Query.php';
$wgAutoloadClasses['Elastica\Filter\Terms'] = $elasticaDir . 'Filter/Terms.php';
$wgAutoloadClasses['Elastica\Index\Settings'] = $elasticaDir . 'Index/Settings.php';
$wgAutoloadClasses['Elastica\Query\AbstractQuery'] = $elasticaDir . 'Query/AbstractQuery.php';
$wgAutoloadClasses['Elastica\Query\Field'] = $elasticaDir . 'Query/Field.php';
$wgAutoloadClasses['Elastica\Query\MatchAll'] = $elasticaDir . 'Query/MatchAll.php';
$wgAutoloadClasses['Elastica\Query\Prefix'] = $elasticaDir . 'Query/Prefix.php';
$wgAutoloadClasses['Elastica\Query\QueryString'] = $elasticaDir . 'Query/QueryString.php';
$wgAutoloadClasses['Elastica\Transport\AbstractTransport'] = $elasticaDir . 'Transport/AbstractTransport.php';
$wgAutoloadClasses['Elastica\Transport\Http'] = $elasticaDir . 'Transport/Http.php';
$wgAutoloadClasses['Elastica\Type\Mapping'] = $elasticaDir . 'Type/Mapping.php';




/**
 * Hooks
 * Also check Setup for other hooks.
 */
$wgHooks['SearchUpdate'][] = function() { return false; };

/**
 * i18n
 */
$wgExtensionMessagesFiles['CirrusSearch'] = $dir . 'CirrusSearch.i18n.php';


/**
 * Setup
 */
$wgExtensionFunctions[] = 'cirrusSearchSetup';
function cirrusSearchSetup() {
	global $wgSearchType, $wgHooks;
	// Install our prefix search hook only if we're enabled.
	if ( $wgSearchType === 'CirrusSearch' ) {
		$wgHooks['PrefixSearchBackend'][] = 'CirrusSearch::prefixSearch';
	}
}