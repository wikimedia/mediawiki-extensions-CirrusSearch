<?php

/**
 * CirrusSearch - Searching for MediaWiki with Elasticsearch.
 *
 * Set $wgSearchType to 'CirrusSearch'
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

// ElasticSearch servers
$wgCirrusSearchServers = array( 'elasticsearch0', 'elasticsearch1', 'elasticsearch2', 'elasticsearch3' );

// Number of shards for each index
$wgCirrusSearchShardCount = array( 'content' => 4, 'general' => 4 );

// Number of replicas per shard for each index
$wgCirrusSearchContentReplicaCount = array( 'content' => 1, 'general' => 1 );

// If true CirrusSearch asks Elasticsearch to perform searches using a mode that should
// product more accurate results at the cost of performance. See this for more info:
// http://www.elasticsearch.org/blog/understanding-query-then-fetch-vs-dfs-query-then-fetch/
$wgCirrusSearchMoreAccurateScoringMode = true;

// Maximum number of terms that we ask phrase suggest to correct.
// See max_errors on http://www.elasticsearch.org/guide/reference/api/search/suggest/
$wgCirrusSearchPhraseSuggestMaxErrors = 5;

// Confidence level required to suggest new phrases.
// See confidence on http://www.elasticsearch.org/guide/reference/api/search/suggest/
$wgCirrusSearchPhraseSuggestConfidence = 2.0;

// Maximum number of redirects per target page to index.  
$wgCirrusSearchIndexedRedirects = 1024;

// Maximum number of linked articles to update every time an article changes.
$wgCirrusSearchLinkedArticlesToUpdate = 5;


$dir = __DIR__ . '/';
$elasticaDir = $dir . 'Elastica/lib/Elastica/';
/**
 * Classes
 */
$wgAutoloadClasses['CirrusSearch'] = $dir . 'CirrusSearch.body.php';
$wgAutoloadClasses['CirrusSearchAnalysisConfigBuilder'] = $dir . 'CirrusSearchAnalysisConfigBuilder.php';
$wgAutoloadClasses['CirrusSearchConnection'] = $dir . 'CirrusSearchConnection.php';
$wgAutoloadClasses['CirrusSearchMappingConfigBuilder'] = $dir . 'CirrusSearchMappingConfigBuilder.php';
$wgAutoloadClasses['CirrusSearchSearcher'] = $dir . 'CirrusSearchSearcher.php';
$wgAutoloadClasses['CirrusSearchTextSanitizer'] = $dir . 'CirrusSearchTextSanitizer.php';
$wgAutoloadClasses['CirrusSearchUpdater'] = $dir . 'CirrusSearchUpdater.php';


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
$wgAutoloadClasses['Elastica\Script'] = $elasticaDir . 'Script.php';
$wgAutoloadClasses['Elastica\Search'] = $elasticaDir . 'Search.php';
$wgAutoloadClasses['Elastica\SearchableInterface'] = $elasticaDir . 'SearchableInterface.php';
$wgAutoloadClasses['Elastica\Status'] = $elasticaDir . 'Status.php';
$wgAutoloadClasses['Elastica\Type'] = $elasticaDir . 'Type.php';
$wgAutoloadClasses['Elastica\Util'] = $elasticaDir . 'Util.php';
$wgAutoloadClasses['Elastica\Bulk\Action'] = $elasticaDir . 'Bulk/Action.php';
$wgAutoloadClasses['Elastica\Bulk\Action\AbstractDocument'] = $elasticaDir . 'Bulk/Action/AbstractDocument.php';
$wgAutoloadClasses['Elastica\Bulk\Action\IndexDocument'] = $elasticaDir . 'Bulk/Action/IndexDocument.php';
$wgAutoloadClasses['Elastica\Bulk\Response'] = $elasticaDir . 'Bulk/Response.php';
$wgAutoloadClasses['Elastica\Bulk\ResponseSet'] = $elasticaDir . 'Bulk/ResponseSet.php';
$wgAutoloadClasses['Elastica\Exception\BulkException'] = $elasticaDir . 'Exception/BulkException.php';
$wgAutoloadClasses['Elastica\Exception\ClientException'] = $elasticaDir . 'Exception/ClientException.php';
$wgAutoloadClasses['Elastica\Exception\ConnectionException'] = $elasticaDir . 'Exception/ConnectionException.php';
$wgAutoloadClasses['Elastica\Exception\ExceptionInterface'] = $elasticaDir . 'Exception/ExceptionInterface.php';
$wgAutoloadClasses['Elastica\Exception\InvalidException'] = $elasticaDir . 'Exception/InvalidException.php';
$wgAutoloadClasses['Elastica\Exception\NotFoundException'] = $elasticaDir . 'Exception/NotFoundException.php';
$wgAutoloadClasses['Elastica\Exception\ResponseException'] = $elasticaDir . 'Exception/ResponseException.php';
$wgAutoloadClasses['Elastica\Exception\Bulk\ResponseException'] = $elasticaDir . 'Exception/Bulk/ResponseException.php';
$wgAutoloadClasses['Elastica\Exception\Bulk\Response\ActionException'] = $elasticaDir . 'Exception/Bulk/Response/ActionException.php';
$wgAutoloadClasses['Elastica\Exception\Connection\HttpException'] = $elasticaDir . 'Exception/Connection/HttpException.php';
$wgAutoloadClasses['Elastica\Filter\AbstractFilter'] = $elasticaDir . 'Filter/AbstractFilter.php';
$wgAutoloadClasses['Elastica\Filter\AbstractMulti'] = $elasticaDir . 'Filter/AbstractMulti.php';
$wgAutoloadClasses['Elastica\Filter\Bool'] = $elasticaDir . 'Filter/Bool.php';
$wgAutoloadClasses['Elastica\Filter\Prefix'] = $elasticaDir . 'Filter/Prefix.php';
$wgAutoloadClasses['Elastica\Filter\Query'] = $elasticaDir . 'Filter/Query.php';
$wgAutoloadClasses['Elastica\Filter\Terms'] = $elasticaDir . 'Filter/Terms.php';
$wgAutoloadClasses['Elastica\Index\Settings'] = $elasticaDir . 'Index/Settings.php';
$wgAutoloadClasses['Elastica\Index\Status'] = $elasticaDir . 'Index/Status.php';
$wgAutoloadClasses['Elastica\Query\AbstractQuery'] = $elasticaDir . 'Query/AbstractQuery.php';
$wgAutoloadClasses['Elastica\Query\CustomScore'] = $elasticaDir . 'Query/CustomScore.php';
$wgAutoloadClasses['Elastica\Query\Field'] = $elasticaDir . 'Query/Field.php';
$wgAutoloadClasses['Elastica\Query\MatchAll'] = $elasticaDir . 'Query/MatchAll.php';
$wgAutoloadClasses['Elastica\Query\Match'] = $elasticaDir . 'Query/Match.php';
$wgAutoloadClasses['Elastica\Query\Prefix'] = $elasticaDir . 'Query/Prefix.php';
$wgAutoloadClasses['Elastica\Query\QueryString'] = $elasticaDir . 'Query/QueryString.php';
$wgAutoloadClasses['Elastica\Transport\AbstractTransport'] = $elasticaDir . 'Transport/AbstractTransport.php';
$wgAutoloadClasses['Elastica\Transport\Http'] = $elasticaDir . 'Transport/Http.php';
$wgAutoloadClasses['Elastica\Type\Mapping'] = $elasticaDir . 'Type/Mapping.php';




/**
 * Hooks
 * Also check Setup for other hooks.
 */
$wgHooks['LinksUpdateComplete'][] = 'CirrusSearchUpdater::linksUpdateCompletedHook';

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
		$wgHooks['PrefixSearchBackend'][] = 'CirrusSearchSearcher::prefixSearch';
	}
}
