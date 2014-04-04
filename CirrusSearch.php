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

// Number of replicas per shard for each index
// The default of 0 is fine for single-node setups, but if this is
// deployed to a multi-node setting you probably at least want these
// set to 1 for some redundancy, if not 2 for more redundancy.
$wgCirrusSearchReplicaCount = array( 'content' => 0, 'general' => 0 );

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
// any values then the index names must also exist in $wgCirrusSearchShardCount
// and $wgCirrusSearchReplicaCount.
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
// in seconds.
$wgCirrusSearchClientSideUpdateTimeout = 5;

// The amount of time Elasticsearch will wait for search shard actions before
// giving up on them and returning the results from the other shards.  Defaults
// to 20s which is about twice the slowest queries we see.  Some shard actions
// are capable of returning partial results and others are just ignored.
$wgCirrusSearchSearchShardTimeout = '20s';

// Client side timeout for searches in seconds.  Best to keep this double the
// shard timeout to give Elasticsearch a change to timeout the shards and return
// partial results.  Defaults to 20 seconds.
$wgCirrusSearchClientSideSearchTimeout = 40;

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

// When searching for a phrase how many words not searched for can be in the phrase
// before it doesn't match. If I search for "like yellow candy" then phraseSlop of 0
// won't match "like brownish yellow candy" but phraseSlop of 1 will.
$wgCirrusSearchPhraseSlop = 1;

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
// product more accurate results at the cost of performance. See this for more info:
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
$wgCirrusSearchPhraseUseText = false;

// Maximum number of redirects per target page to index.
$wgCirrusSearchIndexedRedirects = 1024;

// Maximum number of newly linked articles to update when an article changes.
$wgCirrusSearchLinkedArticlesToUpdate = 25;

// Maximum number of newly unlinked articles to update when an article changes.
$wgCirrusSearchUnlinkedArticlesToUpdate = 25;

// Weight of fields relative to article text
$wgCirrusSearchWeights = array( 'title' => 20.0, 'redirect' => 15.0, 'heading' => 5.0, 'file_text' => 0.8 );

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

// How long to cache link counts for (in seconds)
$wgCirrusSearchLinkCountCacheTime = 0;

// Configuration parameters passed to more_like_this queries.
$wgCirrusSearchMoreLikeThisConfig = array(
	'min_doc_freq' => 2,              // Minimum number of documents (per shard) that need a term for it to be considered
	'max_query_terms' => 25,
	'min_term_freq' => 2,
	'percent_terms_to_match' => 0.3,
	'min_word_len' => 0,
	'max_word_len' => 0,
);

// Should CirrusSearch aggressively split up compound words?  Good for splitting camelCase, snake_case, etc.
// Changing it requires an in place reindex to take effect.  Currently only available in English.
$wgCirrusSearchUseAggressiveSplitting = true;

// Show the notification about this wiki using CirrusSearch on the search page.
$wgCirrusSearchShowNowUsing = false;

// If Cirrus is enabled as a secondary search, allow users to
// set a preference with Extension:BetaFeatures to set it as
// their primary search engine.
$wgCirrusSearchEnablePref = false;

// Should Cirrus show the score?
$wgCirrusSearchShowScore = false;

// CirrusSearch interwiki searching
// Keys are the interwiki prefix, values are the index to search
// Results are cached.
$wgCirrusSearchInterwikiSources = array();

// How long to cache interwiki search results for (in seconds)
$wgCirrusSearchInterwikiCacheTime = 7200;

$includes = __DIR__ . "/includes/";
$buildDocument = $includes . 'BuildDocument/';
/**
 * Classes
 */
$wgAutoloadClasses['CirrusSearch'] = $includes . 'CirrusSearch.php';
$wgAutoloadClasses['CirrusSearch\BuildDocument\Builder'] = $buildDocument . 'Builder.php';
$wgAutoloadClasses['CirrusSearch\BuildDocument\PageDataBuilder'] = $buildDocument . 'PageDataBuilder.php';
$wgAutoloadClasses['CirrusSearch\BuildDocument\PageTextBuilder'] = $buildDocument . 'PageTextBuilder.php';
$wgAutoloadClasses['CirrusSearch\BuildDocument\ParseBuilder'] = $buildDocument . 'Builder.php';
$wgAutoloadClasses['CirrusSearch\BuildDocument\RedirectsAndIncomingLinks'] = $buildDocument . 'RedirectsAndIncomingLinks.php';
$wgAutoloadClasses['CirrusSearch\AnalysisConfigBuilder'] = $includes . 'AnalysisConfigBuilder.php';
$wgAutoloadClasses['CirrusSearch\Connection'] = $includes . 'Connection.php';
$wgAutoloadClasses['CirrusSearch\DeletePagesJob'] = $includes . 'DeletePagesJob.php';
$wgAutoloadClasses['CirrusSearch\ElasticsearchIntermediary'] = $includes . 'ElasticsearchIntermediary.php';
$wgAutoloadClasses['CirrusSearch\ForceSearchIndex'] = __DIR__ . '/maintenance/forceSearchIndex.php';
$wgAutoloadClasses['CirrusSearch\Hooks'] = $includes . 'Hooks.php';
$wgAutoloadClasses['CirrusSearch\LinksUpdateJob'] = $includes . 'LinksUpdateJob.php';
$wgAutoloadClasses['CirrusSearch\LinksUpdateSecondaryJob'] = $includes . 'LinksUpdateSecondaryJob.php';
$wgAutoloadClasses['CirrusSearch\FullTextResultsType'] = $includes . 'ResultsType.php';
$wgAutoloadClasses['CirrusSearch\InterwikiResultsType'] = $includes . 'ResultsType.php';
$wgAutoloadClasses['CirrusSearch\InterwikiSearcher'] = $includes . 'InterwikiSearcher.php';
$wgAutoloadClasses['CirrusSearch\Job'] = $includes . 'Job.php';
$wgAutoloadClasses['CirrusSearch\MappingConfigBuilder'] = $includes . 'MappingConfigBuilder.php';
$wgAutoloadClasses['CirrusSearch\MassIndexJob'] = $includes . 'MassIndexJob.php';
$wgAutoloadClasses['CirrusSearch\NearMatchPicker'] = $includes . 'NearMatchPicker.php';
$wgAutoloadClasses['CirrusSearch\OtherIndexes'] = $includes . 'OtherIndexes.php';
$wgAutoloadClasses['CirrusSearch\OtherIndexJob'] = $includes . 'OtherIndexJob.php';
$wgAutoloadClasses['CirrusSearch\ReindexForkController'] = $includes . 'ReindexForkController.php';
$wgAutoloadClasses['CirrusSearch\Result'] = $includes . 'Result.php';
$wgAutoloadClasses['CirrusSearch\ResultSet'] = $includes . 'ResultSet.php';
$wgAutoloadClasses['CirrusSearch\ResultsType'] = $includes . 'ResultsType.php';
$wgAutoloadClasses['CirrusSearch\Searcher'] = $includes . 'Searcher.php';
$wgAutoloadClasses['CirrusSearch\TitleResultsType'] = $includes . 'ResultsType.php';
$wgAutoloadClasses['CirrusSearch\UpdateSearchIndexConfig'] = __DIR__ . '/maintenance/updateSearchIndexConfig.php';
$wgAutoloadClasses['CirrusSearch\UpdateVersionIndex'] = __DIR__ . '/maintenance/updateVersionIndex.php';
$wgAutoloadClasses['CirrusSearch\Updater'] = $includes . 'Updater.php';

/**
 * Hooks
 */
$wgHooks[ 'CirrusSearchBuildDocumentFinishBatch'][] = 'CirrusSearch\BuildDocument\RedirectsAndIncomingLinks::finishBatch';
$wgHooks[ 'CirrusSearchBuildDocumentLinks'][] = 'CirrusSearch\BuildDocument\RedirectsAndIncomingLinks::buildDocument';
$wgHooks[ 'AfterImportPage' ][] = 'CirrusSearch\Hooks::onAfterImportPage';
$wgHooks[ 'ApiBeforeMain' ][] = 'CirrusSearch\Hooks::apiBeforeMainHook';
$wgHooks[ 'ArticleDeleteComplete' ][] = 'CirrusSearch\Hooks::articleDeleteCompleteHook';
$wgHooks[ 'ArticleRevisionVisibilitySet' ][] = 'CirrusSearch\Hooks::onRevisionDelete';
$wgHooks[ 'BeforeInitialize' ][] = 'CirrusSearch\Hooks::beforeInitializeHook';
$wgHooks[ 'GetBetaFeaturePreferences' ][] = 'CirrusSearch\Hooks::getPreferencesHook';
$wgHooks[ 'LinksUpdateComplete' ][] = 'CirrusSearch\Hooks::linksUpdateCompletedHook';
$wgHooks[ 'SoftwareInfo' ][] = 'CirrusSearch\Hooks::softwareInfoHook';
$wgHooks[ 'SpecialSearchResultsPrepend' ][] = 'CirrusSearch\Hooks::specialSearchResultsPrependHook';
$wgHooks[ 'UnitTestsList' ][] = 'CirrusSearch\Hooks::getUnitTestsList';


/**
 * i18n
 */
$wgMessagesDirs['CirrusSearch'] = __DIR__ . '/i18n';
$wgExtensionMessagesFiles['CirrusSearch'] = __DIR__ . '/CirrusSearch.i18n.php';

/**
 * Jobs
 */
$wgJobClasses[ 'cirrusSearchDeletePages' ] = 'CirrusSearch\DeletePagesJob';
$wgJobClasses[ 'cirrusSearchLinksUpdate' ] = 'CirrusSearch\LinksUpdateJob';
$wgJobClasses[ 'cirrusSearchLinksUpdatePrioritized' ] = 'CirrusSearch\LinksUpdateJob';
$wgJobClasses[ 'cirrusSearchLinksUpdateSecondary' ] = 'CirrusSearch\LinksUpdateSecondaryJob';
$wgJobClasses[ 'cirrusSearchMassIndex' ] = 'CirrusSearch\MassIndexJob';
$wgJobClasses[ 'cirrusSearchOtherIndex' ] = 'CirrusSearch\OtherIndexJob';

/**
 * Jenkins configuration required to get all the browser tests passing cleanly.
 * Note that it is only hooked for browser tests.
 */
if ( isset( $wgWikimediaJenkinsCI ) && $wgWikimediaJenkinsCI === true && (
		PHP_SAPI !== 'cli' ||    // If we're not in the CLI then this is certainly a browser test
		strpos( getenv( 'JOB_NAME' ), 'browsertests' ) !== false ) ) {
	require( __DIR__ . '/tests/jenkins/Jenkins.php' );
}
