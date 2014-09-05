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
	'version'        => '0.2'
);

/**
 * Configuration
 */

// ElasticSearch servers
$wgCirrusSearchServers = array( 'localhost' );

// How many times to attempt connecting to a given server
// If you're behind LVS and everything looks like one server,
// you may want to reattempt 2 or 3 times.
$wgCirrusSearchConnectionAttempts = 1;

// Number of shards for each index
$wgCirrusSearchShardCount = array( 'content' => 4, 'general' => 4 );

// Number of replicas Elasticsearch can expand or contract to. This allows for
// easy development and deployment to a single node (0 replicas) to scale up to
// higher levels of replication. You if you need more redundancy you could
// adjust this to '0-10' or '0-all' or even 'false' (string, not boolean) to
// disable the behavior entirely. The default should be fine for most people.
$wgCirrusSearchReplicas = '0-2';

// Number of shards allowed on the same elasticsearch node.  Set this to 1 to
// prevent two shards from the same high traffic index from being allocated
// onto the same node.
$wgCirrusSearchMaxShardsPerNode = array();
// Example: $wgCirrusSearchMaxShardsPerNode[ 'content' ] = 1;

// How many seconds must a search of Elasticsearch be before we consider it
// slow?  Default value is 10 seconds which should be fine for catching the rare
// truely abusive queries.  Use Elasticsearch query more granular logs that
// don't contain user information.
$wgCirrusSearchSlowSearch = 10.0;

// Should CirrusSearch attempt to use the "experimental" highlighter.  It is an
// Elasticsearch plugin that should produce better snippets for search results.
// Installation instructions are here:
// https://github.com/wikimedia/search-highlighter
// If you have the highlighter installed you can switch this on and off so long
// as you don't rebuild the index while
// $wgCirrusSearchOptimizeIndexForExperimentalHighlighter is true.  Setting it
// to true without the highlighter installed will break search.
$wgCirrusSearchUseExperimentalHighlighter = false;

// Should CirrusSearch optimize the index for the experimental highlighter.
// This will speed up indexing, save a ton of space, and speed up highlighting
// slightly.  This only takes effect if you rebuild the index. The downside is
// that you can no longer switch $wgCirrusSearchUseExperimentalHighlighter on
// and off - it has to stay on.
$wgCirrusSearchOptimizeIndexForExperimentalHighlighter = false;

// By default, Cirrus will organize pages into one of two indexes (general or
// content) based on whether a page is in a content namespace. This should
// suffice for most wikis. This setting allows individual namespaces to be
// mapped to specific index suffixes. The keys are the namespace number, and
// the value is a string name of what index suffix to use. Changing this setting
// requires a full reindex (not in-place) of the wiki.  If this setting contains
// any values then the index names must also exist in $wgCirrusSearchShardCount.
$wgCirrusSearchNamespaceMappings = array();

// Extra indexes (if any) you want to search, and for what namespaces?
// The key should be the local namespace, with the value being an array of one
// or more indexes that should be searched as well for that namespace.
//
// NOTE: This setting makes no attempts to ensure compatibility across
// multiple indexes, and basically assumes everyone's using a CirrusSearch
// index that's more or less the same. Most notably, we can't guarantee
// that namespaces match up; so you should only use this for core namespaces
// or other times you can be sure that namespace IDs match 1-to-1.
//
// NOTE Part Two: Adding an index here is cause cirrus to update spawn jobs to
// update that other index, trying to set the local_sites_with_dupe field.  This
// is used to filter duplicates that appear on the remote index.  This is always
// done by a job, even when run from forceSearchIndex.php.  If you add an image
// to your wiki but after it is in the extra search index you'll see duplicate
// results until the job is done.
$wgCirrusSearchExtraIndexes = array();

// Shard timeout for non-maintenance index operations.  This is the amount of
// time Elasticsearch will wait around for an offline primary shard. Currently
// this is just used in page updates and not deletes.  It is defined in
// Elasticsearch's time format which is a string containing a number and then
// a unit which is one of d (days), m (minutes), h (hours), ms (milliseconds) or
// w (weeks).  Cirrus defaults to a very tiny value to prevent job executors
// from waiting around a long time for Elasticsearch.  Instead, the job will
// fail and be retried later.
$wgCirrusSearchUpdateShardTimeout = '1ms';

// Client side timeout for non-maintenance index and delete operations and
// in seconds.   Set it long enough to account for operations that may be
// delayed on the Elasticsearch node.
$wgCirrusSearchClientSideUpdateTimeout = 120;

// The amount of time Elasticsearch will wait for search shard actions before
// giving up on them and returning the results from the other shards.  Defaults
// to 20s for regular searches which is about twice the slowest queries we see.
// Some shard actions are capable of returning partial results and others are
// just ignored.  Regexes default to 120 seconds because they are known to be
// slow at this point.
$wgCirrusSearchSearchShardTimeout = array(
	'default' => '20s',
	'regex' => '120s',
);

// Client side timeout for searches in seconds.  Best to keep this double the
// shard timeout to give Elasticsearch a chance to timeout the shards and return
// partial results.
$wgCirrusSearchClientSideSearchTimeout = array(
	'default' => 40,
	'regex' => 240,
);

// Client side timeout for maintanance operations.  We can't disable the timeout
// all together so we set it to one hour for really long running operations
// like optimize.
$wgCirrusSearchMaintenanceTimeout = 3600;

// Is it ok if the prefix starts on any word in the title or just the first word?
// Defaults to false (first word only) because that is the wikipedia behavior and so
// what we expect users to expect.  Does not effect the prefix: search filter or
// url parameter - that always starts with the first word.  false -> true will break
// prefix searching until an in place reindex is complete.  true -> false is fine
// any time and you can then go false -> true if you haven't run an in place reindex
// since the change.
$wgCirrusSearchPrefixSearchStartsWithAnyWord = false;

// Phrase slop is how many words not searched for can be in the phrase and it'll still
// match. If I search for "like yellow candy" then phraseSlop of 0 won't match "like
// brownish yellow candy" but phraseSlop of 1 will.  The 'precise' key is for matching
// quoted text.  The 'default' key is for matching quoted text that ends in a ~.
// The 'boost' key is used for the phrase rescore that boosts phrase matches on queries
// that don't already contain phrases.
$wgCirrusSearchPhraseSlop = array( 'precise' => 0, 'default' => 0, 'boost' => 1 );

// If the search doesn't include any phrases (delimited by quotes) then we try wrapping
// the whole thing in quotes because sometimes that can turn up better results. This is
// the boost that we give such matches. Set this less than or equal to 1.0 to turn off
// this feature.
$wgCirrusSearchPhraseRescoreBoost = 10.0;

// Number of documents per shard for which automatic phrase matches are performed if it
// is enabled.  Note that if both function and phrase rescoring is required then the
// phrase rescore window is used.  TODO update this once Elasticsearch supports multiple
// rescore windows.
$wgCirrusSearchPhraseRescoreWindowSize = 1024;

// Number of documents per shard for which function scoring is applied.  This is stuff
// like incoming links boost, prefer-recent decay, and boost-templates.
$wgCirrusSearchFunctionRescoreWindowSize = 8192;

// If true CirrusSearch asks Elasticsearch to perform searches using a mode that should
// produce more accurate results at the cost of performance. See this for more info:
// http://www.elasticsearch.org/blog/understanding-query-then-fetch-vs-dfs-query-then-fetch/
$wgCirrusSearchMoreAccurateScoringMode = true;

// Maximum number of terms that we ask phrase suggest to correct.
// See max_errors on http://www.elasticsearch.org/guide/reference/api/search/suggest/
$wgCirrusSearchPhraseSuggestMaxErrors = 2;

// Confidence level required to suggest new phrases.
// See confidence on http://www.elasticsearch.org/guide/reference/api/search/suggest/
$wgCirrusSearchPhraseSuggestConfidence = 2.0;

// Look for suggestions in the article text?  Changing this from false to true will
// break search until you perform an in place index rebuild.  Changing it from true
// to false is ok and then you can change it back to true so long as you _haven't_
// done an index rebuild since then.  If you perform an in place index rebuild after
// changing this to false then you'll see some space savings.
$wgCirrusSearchPhraseSuggestUseText = false;

// Maximum number of redirects per target page to index.
$wgCirrusSearchIndexedRedirects = 1024;

// Maximum number of newly linked articles to update when an article changes.
$wgCirrusSearchLinkedArticlesToUpdate = 25;

// Maximum number of newly unlinked articles to update when an article changes.
$wgCirrusSearchUnlinkedArticlesToUpdate = 25;

// Weight of fields.  Must be integers not decimals.  If $wgCirrusSearchAllFields['use']
// is false this can be changed on the fly.  If it is true then changes to this require
// an in place reindex to take effect.
$wgCirrusSearchWeights = array(
	'title' => 40,
	'redirect' => 30,
	'category' => 16,
	'heading' => 10,
	'opening_text' => 6,
	'text' => 2,
	'auxiliary_text' => 1,
	'file_text' => 1,
);

// Enable building and using of "all" fields that contain multiple copies of other fields
// for weighting.  These all fields exist entirely to speed up the full_text query type by
// baking the weights above into a single field.  This is useful because it drasticly
// reduces the random io to power the query from 14 term queries per term in the query
// string to 2.  Each term query is potentially one or two disk random io actions.  The
// reduction isn't strictly 7:1 because we skip file_text in non file namespace (now 6:1)
// and the near match fields (title and redirect) also kick it, but only once per query.
// Also don't forget the io from the phrase rescore - this helps with that, but its even
// more muddy how much.
// Note setting 'use' to true without having set 'build' to true and performing an in place
// reindex will cause all searches to find nothing.
$wgCirrusSearchAllFields = array( 'build' => false, 'use' => false );

// The method Cirrus will use to extract the opening section of the text.  Valid values are:
// * first_heading - Wikipedia style.  Grab the text before the first heading (h1-h6) tag.
// * none - Do not extract opening text and do not search it.
$wgCirrusSearchBoostOpening = 'first_heading';

// Weight of fields that match via "near_match" which is ordered.
$wgCirrusSearchNearMatchWeight = 2;

// Weight of stemmed fields relative to unstemmed.  Meaning if searching for <used>, <use> is only
// worth this much while <used> is worth 1.  Searching for <"used"> will still only find exact
// matches.
$wgCirrusSearchStemmedWeight = 0.5;

// Weight of each namespace relative to NS_MAIN.  If not specified non-talk namespaces default to
// $wgCirrusSearchDefaultNamespaceWeight.  If not specified talk namspaces default to:
//   $wgCirrusSearchTalkNamespaceWeight * weightOfCorrespondingNonTalkNamespace
// The default values below inspired by the configuration used for lsearchd.  Note that _technically_
// NS_MAIN can be overriden with this then 1 just represents what NS_MAIN would have been....
// If you override NS_MAIN here then NS_TALK will still default to:
//   $wgCirrusSearchNamespaceWeights[ NS_MAIN ] * wgCirrusSearchTalkNamespaceWeight
$wgCirrusSearchNamespaceWeights = array(
	NS_USER => 0.05,
	NS_PROJECT => 0.1,
	NS_MEDIAWIKI => 0.05,
	NS_TEMPLATE => 0.005,
	NS_HELP => 0.1,
);

// Default weight of non-talks namespaces
$wgCirrusSearchDefaultNamespaceWeight = 0.2;

// Default weight of a talk namespace relative to its corresponding non-talk namespace.
$wgCirrusSearchTalkNamespaceWeight = 0.25;

// Default weight of language field for multilingual wikis.
// 'user' is the weight given to the user's language
// 'wiki' is the weight given to the wiki's content language
// If your wiki is only one language you can leave these at 0, otherwise try setting it
// to something like 5.0 for 'user' and 2.5 for 'wiki'
$wgCirrusSearchLanguageWeight = array(
	'user' => 0.0,
	'wiki' => 0.0,
);

// Portion of an article's score that decays with time since it's last update.  Defaults to 0
// meaning don't decay the score at all unless prefer-recent: prefixes the query.
$wgCirrusSearchPreferRecentDefaultDecayPortion = 0;

// Portion of an article's score that decays with time if prefer-recent: prefixes the query but
// doesn't specify a portion.  Defaults to .6 because that approximates the behavior that
// wikinews has been using for years.  An article 160 days old is worth about 70% of its new score.
$wgCirrusSearchPreferRecentUnspecifiedDecayPortion = .6;

// Default number of days it takes the portion of an article's score that decays with time since
// last update to half way decay to use if prefer-recent: prefixes query and doesn't specify a
// half life or $wgCirrusSearchPreferRecentDefaultDecayPortion is non 0.  Default to 157 because
// that approximates the behavior that wikinews has been using for years.
$wgCirrusSearchPreferRecentDefaultHalfLife = 160;

// Configuration parameters passed to more_like_this queries.
$wgCirrusSearchMoreLikeThisConfig = array(
	'min_doc_freq' => 2,              // Minimum number of documents (per shard) that need a term for it to be considered
	'max_query_terms' => 25,
	'min_term_freq' => 2,
	'percent_terms_to_match' => 0.3,
	'min_word_len' => 0,
	'max_word_len' => 0,
);

// Show the notification about this wiki using CirrusSearch on the search page.
$wgCirrusSearchShowNowUsing = false;

// If Cirrus is enabled as a secondary search, allow users to
// set a preference with Extension:BetaFeatures to set it as
// their primary search engine.
$wgCirrusSearchEnablePref = false;

// CirrusSearch interwiki searching
// Keys are the interwiki prefix, values are the index to search
// Results are cached.
$wgCirrusSearchInterwikiSources = array();

// How long to cache interwiki search results for (in seconds)
$wgCirrusSearchInterwikiCacheTime = 7200;

// Configuration for backups, array format is as follows:
//  array(
//    'my_backups' => array( 'type' => 'fs', 'location' => '/bar/baz/' )
//  )
//
// See the Elasticsearch configuration for more settings you can give, note
// that type and location are required.
$wgCirrusSearchBackup = array();

// The seconds Elasticsearch will wait to batch index changes before making
// them available for search.  Lower values make search more real time but put
// more load on Elasticsearch.  Defaults to 1 second because that is the default
// in Elasticsearch.  Changing this will immediately effect wait time on
// secondary (links) update if those allow waiting (basically if you use Redis
// for the job queue).  For it to effect Elasticsearch you'll have to rebuild
// the index.
$wgCirrusSearchRefreshInterval = 1;

// Delay between when the job is queued for a change and when the job can be
// unqueued.  The idea is to let the job queue deduplication logic take care
// of preventing multiple updates for frequently changed pages and to combine
// many of the secondary changes from template edits into a single update.
// Note that this does not work with every job queue implementation.  It works
// with JobQueueRedis but is ignored with JobQueueDB.
$wgCirrusSearchUpdateDelay = array(
	'prioritized' => 0,
	'default' => 0,
);

// List of plugins that Cirrus should ignore when it scans for plugins.  This
// will cause the plugin not to be used by updateSearchIndexConfig.php and
// friends.
$wgCirrusSearchBannedPlugins = array();

// Number of times to instruct Elasticsearch to retry updates that fail on
// version conflicts.  While we do have a version for each page in mediawiki
// (the revision timestamp) using it for versioning is a bit tricky because
// Cirrus uses two pass indexing the first time and sometimes needs to force
// updates.  This is simpler but theoretically will put more load on
// Elasticsearch.  At this point, though, we believe the load not to be
// substantial.
$wgCirrusSearchUpdateConflictRetryCount = 5;

// Number of characters to include in article fragments.
$wgCirrusSearchFragmentSize = 150;

// Should we add a cache warmer that searches for the main page to the content
// namespace?
// @see http://www.elasticsearch.org/guide/en/elasticsearch/reference/current/indices-warmers.html
$wgCirrusSearchMainPageCacheWarmer = true;

// Other cache warmers.  Form is index name => array(searches).  See examples
// commented out below.
$wgCirrusSearchCacheWarmers = array();
// $wgCirrusSearchCacheWarmers[ 'content' ][] = 'foo bar';
// $wgCirrusSearchCacheWarmers[ 'content' ][] = 'batman';
// $wgCirrusSearchCacheWarmers[ 'general' ][] = 'template:noble pipe';

// Whether to boost searches based on link counts. Default is true
// which most wikis will want. Edge cases will want to turn this off.
$wgCirrusSearchBoostLinks = true;

// Should Cirrus power Special:Random?  The result is truely random BUT it is
// somewhat expensive to generate.
$wgCirrusSearchPowerSpecialRandom = true;

// Shard allocation settings. The include/exclude/require top level keys are
// the type of rule to use, the names should be self explanatory. The values
// are an array of keys and values of different rules to apply to an index.
//
// For example: if you wanted to make sure this index was only allocated to
// servers matching a specific IP block, you'd do this:
//    $wgCirrusSearchIndexAllocation['require'] = array( '_ip' => '192.168.1.*' );
// Or let's say you want to keep an index off a given host:
//    $wgCirrusSearchIndexAllocation['exclude'] = array( '_host' => 'badserver01' );
//
// Note that if you use anything other than the magic values of _ip, _name, _id
// or _host it requires you to configure the host keys/values on your server(s)
//
// http://www.elasticsearch.org/guide/en/elasticsearch/reference/current/index-modules-allocation.html
$wgCirrusSearchIndexAllocation = array(
	'include' => array(),
	'exclude' => array(),
	'require' => array(),
);

// Dumpable config parameters.  These are known not to include any private
// information and thus safe to include in the config dump.  To disable the
// config dump entirely add this to your configuration after including:
// CirrusSearch.php:
// $wgApiModules['cirrus-config-dump'] = 'ApiDisabled';
$wgCirrusSearchConfigDumpWhiteList = array(
	'servers',
	'connectionAttempts',
	'shardCount',
	'replicas',
	'slowSearch',
	'useExperimentalHighlighter',
	'optimizeIndexForExperimentalHighlighter',
	'namespaceMappings',
	'extraIndexes',
	'updateShardTimeout',
	'clientSideUpdateTimeout',
	'searchShardTimeout',
	'clientSizeSearchTimeout',
	'maintenanceTimeout',
	'prefixSearchStartsWithAnyWord',
	'phraseSlop',
	'phraseRescoreBoost',
	'phraseRescoreWindowSize',
	'functionRescoreWindowSize',
	'moreAccurateScoringMode',
	'phraseSuggestMaxErrors',
	'phraseSuggestConfidence',
	'phraseSuggestUseText',
	'indexedRedirects',
	'linkedArticlesToUpdate',
	'unlikedArticlesToUpdate',
	'weights',
	'allFields',
	'boostOpening',
	'nearMatchWeight',
	'stemmedWeight',
	'namespaceWeights',
	'defaultNamespaceWeight',
	'talkeNamespaceWeight',
	'languageWeight',
	'preferRecentDefaultDecayPortion',
	'preferRecentUnspecifiedDecayPortion',
	'preferRecentDefaultHalfLife',
	'moreLikeThisConfig',
	'showNowUsing',
	'enablePref',
	'interwikiSources',
	'interwikiCacheTime',
	'backup',
	'refreshInterval',
	'bannedPlugins',
	'updateConflictRetryCount',
	'fragmentSize',
	'mainPageCacheWarmer',
	'cacheWarmers',
	'boostLinks',
	'powerSpecialRandom',
	'indexAllocation',
);

$includes = __DIR__ . "/includes/";
$buildDocument = $includes . 'BuildDocument/';
$jobsDir = $includes . 'Job/';
$maintenanceDir = $includes . 'Maintenance/';
$sanity = $includes . 'Sanity/';
$search = $includes . 'Search/';

/**
 * Classes
 */
$wgAutoloadClasses['CirrusSearch'] = $includes . 'CirrusSearch.php';
$wgAutoloadClasses['CirrusSearch\ApiConfigDump'] = $includes . 'ApiConfigDump.php';
$wgAutoloadClasses['CirrusSearch\BuildDocument\Builder'] = $buildDocument . 'Builder.php';
$wgAutoloadClasses['CirrusSearch\BuildDocument\FileDataBuilder'] = $buildDocument . 'FileDataBuilder.php';
$wgAutoloadClasses['CirrusSearch\BuildDocument\PageDataBuilder'] = $buildDocument . 'PageDataBuilder.php';
$wgAutoloadClasses['CirrusSearch\BuildDocument\PageTextBuilder'] = $buildDocument . 'PageTextBuilder.php';
$wgAutoloadClasses['CirrusSearch\BuildDocument\ParseBuilder'] = $buildDocument . 'Builder.php';
$wgAutoloadClasses['CirrusSearch\BuildDocument\RedirectsAndIncomingLinks'] = $buildDocument . 'RedirectsAndIncomingLinks.php';
$wgAutoloadClasses['CirrusSearch\Connection'] = $includes . 'Connection.php';
$wgAutoloadClasses['CirrusSearch\Dump'] = $includes . 'Dump.php';
$wgAutoloadClasses['CirrusSearch\ElasticsearchIntermediary'] = $includes . 'ElasticsearchIntermediary.php';
$wgAutoloadClasses['CirrusSearch\ForceSearchIndex'] = __DIR__ . '/maintenance/forceSearchIndex.php';
$wgAutoloadClasses['CirrusSearch\Hooks'] = $includes . 'Hooks.php';
$wgAutoloadClasses['CirrusSearch\InterwikiSearcher'] = $includes . 'InterwikiSearcher.php';
$wgAutoloadClasses['CirrusSearch\Job\Job'] = $jobsDir . 'Job.php';
$wgAutoloadClasses['CirrusSearch\Job\DeletePages'] = $jobsDir . 'DeletePages.php';
$wgAutoloadClasses['CirrusSearch\Job\LinksUpdate'] = $jobsDir . 'LinksUpdate.php';
$wgAutoloadClasses['CirrusSearch\Job\LinksUpdateSecondary'] = $jobsDir . 'LinksUpdateSecondary.php';
$wgAutoloadClasses['CirrusSearch\Job\MassIndex'] = $jobsDir . 'MassIndex.php';
$wgAutoloadClasses['CirrusSearch\Job\OtherIndex'] = $jobsDir . 'OtherIndex.php';
$wgAutoloadClasses['CirrusSearch\Maintenance\AnalysisConfigBuilder'] = $maintenanceDir . 'AnalysisConfigBuilder.php';
$wgAutoloadClasses['CirrusSearch\Maintenance\CacheWarmers'] = $maintenanceDir . 'CacheWarmers.php';
$wgAutoloadClasses['CirrusSearch\Maintenance\ChunkBuilder'] = $maintenanceDir . 'ChunkBuilder.php';
$wgAutoloadClasses['CirrusSearch\Maintenance\ReindexForkController'] = $maintenanceDir . 'ReindexForkController.php';
$wgAutoloadClasses['CirrusSearch\Maintenance\MappingConfigBuilder'] = $maintenanceDir . 'MappingConfigBuilder.php';
$wgAutoloadClasses['CirrusSearch\Maintenance\ShardAllocation'] = $maintenanceDir . 'ShardAllocation.php';
$wgAutoloadClasses['CirrusSearch\NearMatchPicker'] = $includes . 'NearMatchPicker.php';
$wgAutoloadClasses['CirrusSearch\OtherIndexes'] = $includes . 'OtherIndexes.php';
$wgAutoloadClasses['CirrusSearch\Sanity\Checker'] = $sanity . 'Checker.php';
$wgAutoloadClasses['CirrusSearch\Sanity\NoopRemediator'] = $sanity . 'Remediator.php';
$wgAutoloadClasses['CirrusSearch\Sanity\PrintingRemediator'] = $sanity . 'Remediator.php';
$wgAutoloadClasses['CirrusSearch\Sanity\QueueingRemediator'] = $sanity . 'QueueingRemediator.php';
$wgAutoloadClasses['CirrusSearch\Sanity\Remediator'] = $sanity . 'Remediator.php';
$wgAutoloadClasses['CirrusSearch\Search\Escaper'] = $search . 'Escaper.php';
$wgAutoloadClasses['CirrusSearch\Search\FancyTitleResultsType'] = $search . 'ResultsType.php';
$wgAutoloadClasses['CirrusSearch\Search\Filters'] = $search . 'Filters.php';
$wgAutoloadClasses['CirrusSearch\Search\FullTextResultsType'] = $search . 'ResultsType.php';
$wgAutoloadClasses['CirrusSearch\Search\IdResultsType'] = $search . 'ResultsType.php';
$wgAutoloadClasses['CirrusSearch\Search\InterwikiResultsType'] = $search . 'ResultsType.php';
$wgAutoloadClasses['CirrusSearch\Search\Result'] = $search . 'Result.php';
$wgAutoloadClasses['CirrusSearch\Search\ResultSet'] = $search . 'ResultSet.php';
$wgAutoloadClasses['CirrusSearch\Search\ResultsType'] = $search . 'ResultsType.php';
$wgAutoloadClasses['CirrusSearch\Search\TitleResultsType'] = $search . 'ResultsType.php';
$wgAutoloadClasses['CirrusSearch\Searcher'] = $includes . 'Searcher.php';
$wgAutoloadClasses['CirrusSearch\UpdateSearchIndexConfig'] = __DIR__ . '/maintenance/updateSearchIndexConfig.php';
$wgAutoloadClasses['CirrusSearch\UpdateVersionIndex'] = __DIR__ . '/maintenance/updateVersionIndex.php';
$wgAutoloadClasses['CirrusSearch\Updater'] = $includes . 'Updater.php';
$wgAutoloadClasses['CirrusSearch\Util'] = $includes . 'Util.php';
$wgAutoloadClasses['CirrusSearch\Version'] = $includes . 'Version.php';

/**
 * Hooks
 */
$wgHooks[ 'CirrusSearchBuildDocumentFinishBatch'][] = 'CirrusSearch\BuildDocument\RedirectsAndIncomingLinks::finishBatch';
$wgHooks[ 'CirrusSearchBuildDocumentLinks'][] = 'CirrusSearch\BuildDocument\RedirectsAndIncomingLinks::buildDocument';
$wgHooks[ 'AfterImportPage' ][] = 'CirrusSearch\Hooks::onAfterImportPage';
$wgHooks[ 'ApiBeforeMain' ][] = 'CirrusSearch\Hooks::onApiBeforeMain';
$wgHooks[ 'ArticleDelete' ][] = 'CirrusSearch\Hooks::onArticleDelete';
$wgHooks[ 'ArticleDeleteComplete' ][] = 'CirrusSearch\Hooks::onArticleDeleteComplete';
$wgHooks[ 'ArticleRevisionVisibilitySet' ][] = 'CirrusSearch\Hooks::onRevisionDelete';
$wgHooks[ 'BeforeInitialize' ][] = 'CirrusSearch\Hooks::onBeforeInitialize';
$wgHooks[ 'GetBetaFeaturePreferences' ][] = 'CirrusSearch\Hooks::onGetBetaFeaturePreferences';
$wgHooks[ 'LinksUpdateComplete' ][] = 'CirrusSearch\Hooks::onLinksUpdateCompleted';
$wgHooks[ 'SoftwareInfo' ][] = 'CirrusSearch\Hooks::onSoftwareInfo';
$wgHooks[ 'SpecialRandomGetRandomTitle' ][] = 'CirrusSearch\Hooks::onSpecialRandomGetRandomTitle';
$wgHooks[ 'SpecialSearchResultsPrepend' ][] = 'CirrusSearch\Hooks::onSpecialSearchResultsPrepend';
$wgHooks[ 'TitleMove' ][] = 'CirrusSearch\Hooks::onTitleMove';
$wgHooks[ 'TitleMoveComplete' ][] = 'CirrusSearch\Hooks::onTitleMoveComplete';
$wgHooks[ 'UnitTestsList' ][] = 'CirrusSearch\Hooks::onUnitTestsList';

/**
 * i18n
 */
$wgMessagesDirs['CirrusSearch'] = __DIR__ . '/i18n';
$wgExtensionMessagesFiles['CirrusSearch'] = __DIR__ . '/CirrusSearch.i18n.php';

/**
 * Jobs
 */
$wgJobClasses[ 'cirrusSearchDeletePages' ] = 'CirrusSearch\Job\DeletePages';
$wgJobClasses[ 'cirrusSearchLinksUpdate' ] = 'CirrusSearch\Job\LinksUpdate';
$wgJobClasses[ 'cirrusSearchLinksUpdatePrioritized' ] = 'CirrusSearch\Job\LinksUpdate';
$wgJobClasses[ 'cirrusSearchLinksUpdateSecondary' ] = 'CirrusSearch\Job\LinksUpdateSecondary';
$wgJobClasses[ 'cirrusSearchMassIndex' ] = 'CirrusSearch\Job\MassIndex';
$wgJobClasses[ 'cirrusSearchOtherIndex' ] = 'CirrusSearch\Job\OtherIndex';

/**
 * Actions
 */
$wgActions[ 'cirrusdump' ] = 'CirrusSearch\Dump';

/**
 * API
 */
$wgAPIModules['cirrus-config-dump'] = 'CirrusSearch\ApiConfigDump';

/**
 * Jenkins configuration required to get all the browser tests passing cleanly.
 * Note that it is only hooked for browser tests.
 */
if ( isset( $wgWikimediaJenkinsCI ) && $wgWikimediaJenkinsCI === true && (
		PHP_SAPI !== 'cli' ||    // If we're not in the CLI then this is certainly a browser test
		strpos( getenv( 'JOB_NAME' ), 'browsertests' ) !== false ) ) {
	require( __DIR__ . '/tests/jenkins/Jenkins.php' );
}
