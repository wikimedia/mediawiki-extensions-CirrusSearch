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
$wgCirrusSearchContentReplicaCount = array( 'content' => 0, 'general' => 0 );

// Shard timeout for non-maintenance index operations including those done in the web
// process and those done via job queue.  This is the amount of time Elasticsearch
// will wait around for an offline primary shard.  Currently this is just used in
// page updates and not deletes.  If this is specified then page updates cannot use
// the bulk api so they will be less efficient.  Luckily, this isn't used in
// maintenance scripts which really need bulk operations.  It is defined in
// Elasticsearch's time format which is a string containing a number and then a unit
// which is one of d (days), m (minutes), h (hours), ms (milliseconds) or w (weeks).
// Cirrus defaults to a very tiny value to prevent folks from waiting around for
// updates.
$wgCirrusSearchShardTimeout = '1ms';

// Client side timeout for non-maintenance index and delete operations and freshness
// checks in seconds.
$wgCirrusSearchClientSideUpdateTimeout = 5;

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

// Number of documents for which automatic phrase matches are performed if it is enabled.
$wgCirrusSearchPhraseRescoreWindowSize = 1024;

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

// Look for suggestions in the article text?  Changing this from false to true will
// break search until you perform an in place index rebuild.  Changing it from true
// to false is ok and then you can change it back to true so long as you _haven't_
// done an index rebuild since then.  If you perform an in place index rebuild after
// changing this to false then you'll see some space savings.
$wgCirrusSearchPhraseUseText = false;

// Maximum number of redirects per target page to index.  
$wgCirrusSearchIndexedRedirects = 1024;

// Maximum number of newly linked articles to update when an article changes.
$wgCirrusSearchLinkedArticlesToUpdate = 5;

// Maximum number of newly unlinked articles to update when an article changes.
$wgCirrusSearchUnlinkedArticlesToUpdate = 5;

// Weight of fields relative to article text
$wgCirrusSearchWeights = array( 'title' => 20.0, 'redirect' => 15.0, 'heading' => 5.0 );

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

$includes = __DIR__ . "/includes/";
/**
 * Classes
 */
$wgAutoloadClasses['CirrusSearch'] = $includes . 'CirrusSearch.body.php';
$wgAutoloadClasses['CirrusSearchAnalysisConfigBuilder'] = $includes . 'CirrusSearchAnalysisConfigBuilder.php';
$wgAutoloadClasses['CirrusSearchConnection'] = $includes . 'CirrusSearchConnection.php';
$wgAutoloadClasses['CirrusSearchDeletePagesJob'] = $includes . 'CirrusSearchDeletePagesJob.php';
$wgAutoloadClasses['CirrusSearchLinksUpdateJob'] = $includes . 'CirrusSearchLinksUpdateJob.php';
$wgAutoloadClasses['CirrusSearchFullTextResultsType'] = $includes . 'CirrusSearchResultsType.php';
$wgAutoloadClasses['CirrusSearchMappingConfigBuilder'] = $includes . 'CirrusSearchMappingConfigBuilder.php';
$wgAutoloadClasses['CirrusSearchReindexForkController'] = $includes . 'CirrusSearchReindexForkController.php';
$wgAutoloadClasses['CirrusSearchResult'] = $includes . 'CirrusSearchResult.php';
$wgAutoloadClasses['CirrusSearchResultSet'] = $includes . 'CirrusSearchResultSet.php';
$wgAutoloadClasses['CirrusSearchResultsType'] = $includes . 'CirrusSearchResultsType.php';
$wgAutoloadClasses['CirrusSearchSearcher'] = $includes . 'CirrusSearchSearcher.php';
$wgAutoloadClasses['CirrusSearchTextFormatter'] = $includes . 'CirrusSearchTextFormatter.php';
$wgAutoloadClasses['CirrusSearchTitleResultsType'] = $includes . 'CirrusSearchResultsType.php';
$wgAutoloadClasses['CirrusSearchUpdatePagesJob'] = $includes . 'CirrusSearchUpdatePagesJob.php';
$wgAutoloadClasses['CirrusSearchUpdater'] = $includes . 'CirrusSearchUpdater.php';

/**
 * Hooks
 */
$wgHooks[ 'ArticleDeleteComplete' ][] = 'CirrusSearch::articleDeleteCompleteHook';
$wgHooks[ 'LinksUpdateComplete' ][] = 'CirrusSearchUpdater::linksUpdateCompletedHook';
$wgHooks[ 'SoftwareInfo' ][] = 'CirrusSearch::softwareInfoHook';
$wgHooks[ 'SpecialSearchResultsPrepend' ][] = 'CirrusSearch::specialSearchResultsPrependHook';
$wgHooks[ 'GetBetaFeaturePreferences' ][] = 'CirrusSearch::getPreferencesHook';
// Install our prefix search hook only if we're enabled.
$wgExtensionFunctions[] = function() {
	global $wgSearchType, $wgHooks, $wgCirrusSearchEnablePref;
	$user = RequestContext::getMain()->getUser();
	if ( $wgCirrusSearchEnablePref && $user->isLoggedIn() && class_exists( 'BetaFeatures' )
		&& BetaFeatures::isFeatureEnabled( $user, 'cirrussearch-default' )
	) {
		// If the user has the BetaFeature enabled, use Cirrus as default
		$wgSearchType = 'CirrusSearch';
	}
	if ( $wgSearchType === 'CirrusSearch' ) {
		$wgHooks['PrefixSearchBackend'][] = 'CirrusSearch::prefixSearch';
	}
};

/**
 * i18n
 */
$wgExtensionMessagesFiles['CirrusSearch'] = __DIR__ . '/CirrusSearch.i18n.php';

/**
 * Jobs
 */
$wgJobClasses[ 'cirrusSearchDeletePages' ] = 'CirrusSearchDeletePagesJob';
$wgJobClasses[ 'cirrusSearchLinksUpdate' ] = 'CirrusSearchLinksUpdateJob';
$wgJobClasses[ 'cirrusSearchUpdatePages' ] = 'CirrusSearchUpdatePagesJob';
