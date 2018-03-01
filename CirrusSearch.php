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

$wgExtensionCredits['other'][] = [
	'path'           => __FILE__,
	'name'           => 'CirrusSearch',
	'author'         => [ 'Nik Everett', 'Chad Horohoe', 'Erik Bernhardson' ],
	'descriptionmsg' => 'cirrussearch-desc',
	'url'            => 'https://www.mediawiki.org/wiki/Extension:CirrusSearch',
	'version'        => '0.2',
	'license-name'   => 'GPL-2.0-or-later'
];

/**
 * Configuration
 * Please update docs/settings.txt if you add new values!
 */

/**
 * Default cluster for read operations. This is an array key
 * mapping into $wgCirrusSearchClusters. When running multiple
 * clusters this should be pointed to the closest cluster, and
 * can be pointed at an alternate cluster during downtime.
 *
 * As a form of backwards compatibility the existence of
 * $wgCirrusSearchServers will override all cluster configuration.
 */
$wgCirrusSearchDefaultCluster = 'default';

/**
 * Each key is the name of an elasticsearch cluster. The value is
 * a list of addresses to connect to. If no port is specified it
 * defaults to 9200.
 *
 * All writes will be processed in all configured clusters by the
 * ElasticaWrite job, unless $wgCirrusSearchWriteClusters is
 * configured (see below).
 *
 * $wgCirrusSearchClusters = array(
 * 	'dc-foo' => array( 'es01.foo.local', 'es02.foo.local' ),
 * 	'dc-bar' => array( 'es01.bar.local', 'es02.bar.local' ),
 * );
 */
$wgCirrusSearchClusters = [
	'default' => [ 'localhost' ],
];

/**
 * List of clusters that can be used for writing. Must be a subset of keys
 * from $wgCirrusSearchClusters.
 * By default or when set to null, all keys of $wgCirrusSearchClusters are
 * available for writing.
 */
$wgCirrusSearchWriteClusters = null;

/**
 * How many times to attempt connecting to a given server
 * If you're behind LVS and everything looks like one server,
 * you may want to reattempt 2 or 3 times.
 */
$wgCirrusSearchConnectionAttempts = 1;

/**
 * Number of shards for each index
 * You can also set this setting for each cluster:
 * $wgCirrusSearchShardCount = array(
 *  'cluster1' => array( 'content' => 2, 'general' => 2 ),
 *  'cluster2' => array( 'content' => 3, 'general' => 3 ),
 * );
 */
$wgCirrusSearchShardCount = [ 'content' => 4, 'general' => 4, 'titlesuggest' => 4 ];

/**
 * Number of replicas Elasticsearch can expand or contract to. This allows for
 * easy development and deployment to a single node (0 replicas) to scale up to
 * higher levels of replication. You if you need more redundancy you could
 * adjust this to '0-10' or '0-all' or even 'false' (string, not boolean) to
 * disable the behavior entirely. The default should be fine for most people.
 * You can also set this setting for each cluster:
 * $wgCirrusSearchReplicas = array(
 *  'cluster1' => array( 'content' => '0-1', 'general' => '0-2' ),
 *  'cluster2' => array( 'content' => '0-2', 'general' => '0-3' ),
 * );
 */
$wgCirrusSearchReplicas = '0-2';

/**
 * You can also specify this as an array of index type to replica count.  If you
 * do then you must specify all index types.  For example:
 * $wgCirrusSearchReplicas = array( 'content' => '0-3', 'general' => '0-2' );
 */

/**
 * Number of shards allowed on the same elasticsearch node.  Set this to 1 to
 * prevent two shards from the same high traffic index from being allocated
 * onto the same node.
 *
 * @example $wgCirrusSearchMaxShardsPerNode['content'] = 1;
 */
$wgCirrusSearchMaxShardsPerNode = [];

/**
 * How many seconds must a search of Elasticsearch be before we consider it
 * slow?  Default value is 10 seconds which should be fine for catching the rare
 * truly abusive queries.  Use Elasticsearch query more granular logs that
 * don't contain user information.
 */
$wgCirrusSearchSlowSearch = 10.0;

/**
 * Should CirrusSearch attempt to use the "experimental" highlighter.  It is an
 * Elasticsearch plugin that should produce better snippets for search results.
 * Installation instructions are here:
 * https://github.com/wikimedia/search-highlighter
 * If you have the highlighter installed you can switch this on and off so long
 * as you don't rebuild the index while
 * $wgCirrusSearchOptimizeIndexForExperimentalHighlighter is true.  Setting it
 * to true without the highlighter installed will break search.
 */
$wgCirrusSearchUseExperimentalHighlighter = false;

/**
 * Should CirrusSearch optimize the index for the experimental highlighter.
 * This will speed up indexing, save a ton of space, and speed up highlighting
 * slightly.  This only takes effect if you rebuild the index. The downside is
 * that you can no longer switch $wgCirrusSearchUseExperimentalHighlighter on
 * and off - it has to stay on.
 */
$wgCirrusSearchOptimizeIndexForExperimentalHighlighter = false;

/**
 * Should CirrusSearch try to use the wikimedia/extra plugin?  An empty array
 * means don't use it at all.
 *
 * Here is an example to enable faster regex matching:
 * $wgCirrusSearchWikimediaExtraPlugin[ 'regex' ] =
 *     array( 'build', 'use' );
 * The 'build' value instructs Cirrus to build the index required to speed up
 * regex queries.  The 'use' value instructs Cirrus to use it to power regular
 * expression queries.  If 'use' is added before the index is rebuilt with
 * 'build' in the array then regex will fail to find anything. To limit the
 * potential performance impact of regex searches a regex-specific timeout can
 * be set, after which the user will receive partial results and a notice about
 * the timeout. Additionally a regex-specific pool counter can be used to limit
 * the number of regex's being processed in parallel.
 *
 * This turns on noop-detection for updates and is compatible with
 * wikimedia-extra versions 1.3.1, 1.4.2, 1.5.0, and greater:
 * $wgCirrusSearchWikimediaExtraPlugin[ 'super_detect_noop' ] = true;
 *
 * As of elastic 5.5 native scripts have been deprecated the super_detect_noop is
 * now available as a normal script with language "super_detect_noop".
 * If you run elastic prior to 5.5.2 you must enable this option if using
 * super_detect_noop.
 * $wgCirrusSearchWikimediaExtraPlugin['super_detect_noop_enable_native'] = true;
 *
 * Controls the list of extra handlers to set when the noop script
 * is enabled.
 *
 * $wgCirrusSearchWikimediaExtraPlugin[ 'super_detect_noop_handlers' ] = [
 *    'labels' => 'equals'
 * ];
 *
 * This turns on document level noop-detection for updates based on revision
 * ids and is compatible with wikimedia-extra versions 2.3.4.1 and greater:
 * $wgCirrusSearchWikimediaExtraPlugin[ 'documentVersion' ] = true
 *
 * This allows forking on reindexing and is compatible with wikimedia-extra
 * versions 1.3.1, 1.4.2, 1.5.0, and greater:
 * $wgCirrusSearchWikimediaExtraPlugin[ 'id_hash_mod_filter' ] = true;
 *
 * Allows to use lucene tokenizers to activate phrase rescore. This allows not
 * to rely on the presence of spaces (which obviously does not work on spaceless
 * languages). Available since version 5.1.2
 * $wgCirrusSearchWikimediaExtraPlugin['token_count_router'] = true;
 */
$wgCirrusSearchWikimediaExtraPlugin = [];

/**
 * Should CirrusSearch try to support regular expressions with insource:?
 * These can be really expensive, but mostly ok, especially if you have the
 * extra plugin installed. Sometimes they still cause issues though.
 */
$wgCirrusSearchEnableRegex = true;

/**
 * Maximum complexity of regexes.  Raising this will allow more complex
 * regexes use the memory that they need to compile in Elasticsearch.  The
 * default allows reasonably complex regexes and doesn't use _too_ much memory.
 */
$wgCirrusSearchRegexMaxDeterminizedStates = 20000;

/**
 * Maximum complexity of wildcard queries. Raising this value will allow
 * more wildcards in search terms. 500 will allow about 20 wildcards.
 * Setting a high value here can cause the cluster to consume a lot of memory
 * when compiling complex wildcards queries.
 * This setting requires elasticsearch 1.4+. Comment to disable.
 * With elasticsearch 1.4+ if this setting is disabled the default value is
 * 10000.
 * With elasticsearch 1.3 this setting must be disabled.
 * $wgCirrusSearchQueryStringMaxDeterminizedStates = 500;
 */
$wgCirrusSearchQueryStringMaxDeterminizedStates = null;

/**
 * By default, Cirrus will organize pages into one of two indexes (general or
 * content) based on whether a page is in a content namespace. This should
 * suffice for most wikis. This setting allows individual namespaces to be
 * mapped to specific index suffixes. The keys are the namespace number, and
 * the value is a string name of what index suffix to use. Changing this setting
 * requires a full reindex (not in-place) of the wiki.  If this setting contains
 * any values then the index names must also exist in $wgCirrusSearchShardCount.
 */
$wgCirrusSearchNamespaceMappings = [];

/**
 * Extra indexes (if any) you want to search, and for what namespaces?
 * The key should be the local namespace, with the value being an array of one
 * or more indexes that should be searched as well for that namespace.
 *
 * NOTE: This setting makes no attempts to ensure compatibility across
 * multiple indexes, and basically assumes everyone's using a CirrusSearch
 * index that's more or less the same. Most notably, we can't guarantee
 * that namespaces match up; so you should only use this for core namespaces
 * or other times you can be sure that namespace IDs match 1-to-1.
 *
 * NOTE Part Two: Adding an index here is cause cirrus to update spawn jobs to
 * update that other index, trying to set the local_sites_with_dupe field.  This
 * is used to filter duplicates that appear on the remote index.  This is always
 * done by a job, even when run from forceSearchIndex.php.  If you add an image
 * to your wiki but after it is in the extra search index you'll see duplicate
 * results until the job is done.
 */
$wgCirrusSearchExtraIndexes = [];

/**
 * Template boosts to apply to extra index queries. This is pretty much a complete
 * hack, but gets the job done. Top level is a map from the extra index addedby
 * $wgCirrusSearchExtraIndexes to a configuration map. That configuration map must
 * contain a 'wiki' entry with the same value as the 'wiki' field in the documents,
 * and a 'boosts' entry containing a map from template name to boost weight.
 *
 * Example:
 *   $wgCirrusSearchExtraIndexBoostTemplates = [
 *       'commonswiki_file' => [
 *           'wiki' => 'commonswiki',
 *           'boosts' => [
 *               'Template:Valued image' => 1.75
 *               'Template:Assessments' => 1.75,
 *           ],
 *       ]
 *   ];
 */
$wgCirrusSearchExtraIndexBoostTemplates = [];

/**
 * Shard timeout for index operations.  This is the amount of time
 * Elasticsearch will wait around for an offline primary shard. Currently this
 * is just used in page updates and not deletes.  It is defined in
 * Elasticsearch's time format which is a string containing a number and then a
 * unit which is one of d (days), m (minutes), h (hours), ms (milliseconds) or
 * w (weeks).  Cirrus defaults to a very tiny value to prevent job executors
 * from waiting around a long time for Elasticsearch.  Instead, the job will
 * fail and be retried later.
 */
$wgCirrusSearchUpdateShardTimeout = '1ms';

/**
 * Client side timeout for non-maintenance index and delete operations and
 * in seconds.   Set it long enough to account for operations that may be
 * delayed on the Elasticsearch node.
 */
$wgCirrusSearchClientSideUpdateTimeout = 120;

/**
 * Client side timeout when initializing connections.
 * Useful to fail fast if elasticsearch is unreachable.
 * Set to 0 to use Elastica defaults (300 sec)
 * You can also set this setting for each cluster:
 * $wgCirrusSearchClientSideConnectTimeout = array(
 *   'cluster1' => 10,
 *   'cluster2' => 5,
 * )
 */
$wgCirrusSearchClientSideConnectTimeout = 5;

/**
 * The amount of time Elasticsearch will wait for search shard actions before
 * giving up on them and returning the results from the other shards.  Defaults
 * to 20s for regular searches which is about twice the slowest queries we see.
 * Some shard actions are capable of returning partial results and others are
 * just ignored.  Regexes default to 120 seconds because they are known to be
 * slow at this point.
 */
$wgCirrusSearchSearchShardTimeout = [
	'default' => '20s',
	'regex' => '120s',
];

/**
 * Client side timeout for searches in seconds.  Best to keep this double the
 * shard timeout to give Elasticsearch a chance to timeout the shards and return
 * partial results.
 */
$wgCirrusSearchClientSideSearchTimeout = [
	'default' => 40,
	'regex' => 240,
];

/**
 * Client side timeout for maintenance operations.  We can't disable the timeout
 * all together so we set it to one hour for really long running operations
 * like optimize.
 */
$wgCirrusSearchMaintenanceTimeout = 3600;

/**
 * Is it ok if the prefix starts on any word in the title or just the first word?
 * Defaults to false (first word only) because that is the Wikipedia behavior and so
 * what we expect users to expect.  Does not effect the prefix: search filter or
 * url parameter - that always starts with the first word.  false -> true will break
 * prefix searching until an in place reindex is complete.  true -> false is fine
 * any time and you can then go false -> true if you haven't run an in place reindex
 * since the change.
 */
$wgCirrusSearchPrefixSearchStartsWithAnyWord = false;

/**
 * Phrase slop is how many words not searched for can be in the phrase and it'll still
 * match. If I search for "like yellow candy" then phraseSlop of 0 won't match "like
 * brownish yellow candy" but phraseSlop of 1 will.  The 'precise' key is for matching
 * quoted text.  The 'default' key is for matching quoted text that ends in a ~.
 * The 'boost' key is used for the phrase rescore that boosts phrase matches on queries
 * that don't already contain phrases.
 */
$wgCirrusSearchPhraseSlop = [ 'precise' => 0, 'default' => 0, 'boost' => 1 ];

/**
 * If the search doesn't include any phrases (delimited by quotes) then we try wrapping
 * the whole thing in quotes because sometimes that can turn up better results. This is
 * the boost that we give such matches. Set this less than or equal to 1.0 to turn off
 * this feature.
 */
$wgCirrusSearchPhraseRescoreBoost = 10.0;

/**
 * Number of documents per shard for which automatic phrase matches are performed if it
 * is enabled.
 */
$wgCirrusSearchPhraseRescoreWindowSize = 512;

/**
 * Number of documents per shard for which function scoring is applied.  This is stuff
 * like incoming links boost, prefer-recent decay, and boost-templates.
 */
$wgCirrusSearchFunctionRescoreWindowSize = 8192;

/**
 * If true CirrusSearch asks Elasticsearch to perform searches using a mode that should
 * produce more accurate results at the cost of performance. See this for more info:
 * http://www.elasticsearch.org/blog/understanding-query-then-fetch-vs-dfs-query-then-fetch/
 */
$wgCirrusSearchMoreAccurateScoringMode = true;

/**
 * Should the phrase suggester (did you mean) be enabled?
 */
$wgCirrusSearchEnablePhraseSuggest = true;

/**
 * List of additional phrase suggester profiles
 * see profiles/PhraseSuggesterProfiles.config.php
 */
$wgCirrusSearchPhraseSuggestProfiles = [];

/**
 * Set the Phrase suggester settings using the default profile.
 */
$wgCirrusSearchPhraseSuggestSettings = 'default';

/**
 * Use a reverse field to build the did you mean suggestions.
 * This is usefull to workaround the prefix length limitation, by working with a reverse
 * field we can suggest typos correction that appears in the first 2 characters of the word.
 * i.e. Suggesting "search" if the user types "saerch" is possible with the reverse field.
 * Set build to true and reindex before set use to true
 */
$wgCirrusSearchPhraseSuggestReverseField = [
	'build' => false,
	'use' => false,
];

/**
 * Look for suggestions in the article text?
 * An inplace reindex is needed after any changes to this value.
 */
$wgCirrusSearchPhraseSuggestUseText = false;

/**
 * Look for suggestions in the article opening text?
 * An inplace reindex is needed after any changes to this value.
 */
$wgCirrusSearchPhraseSuggestUseOpeningText = false;

/**
 * Allow leading wildcard queries.
 * Searching for terms that have a leading ? or * can be very slow. Turn this off to
 * disable it.  Terms with leading wildcards will have the wildcard escaped.
 */
$wgCirrusSearchAllowLeadingWildcard = true;

/**
 * Maximum number of redirects per target page to index.
 */
$wgCirrusSearchIndexedRedirects = 1024;

/**
 * Maximum number of newly linked articles to update when an article changes.
 */
$wgCirrusSearchLinkedArticlesToUpdate = 25;

/**
 * Maximum number of newly unlinked articles to update when an article changes.
 */
$wgCirrusSearchUnlinkedArticlesToUpdate = 25;

/**
 * Configure the similarity module
 * see profile/SimilarityProfiles.config.php for more details
 */
$wgCirrusSearchSimilarityProfile = 'classic';

/**
 * Extra similarity profiles
 */
$wgCirrusSearchSimilarityProfiles = [];

/**
 * Weight of fields.  Must be integers not decimals.  If $wgCirrusSearchAllFields['use']
 * is false this can be changed on the fly.  If it is true then changes to this require
 * an in place reindex to take effect.
 */
$wgCirrusSearchWeights = [
	'title' => 20,
	'redirect' => 15,
	'category' => 8,
	'heading' => 5,
	'opening_text' => 3,
	'text' => 1,
	'auxiliary_text' => 0.5,
	'file_text' => 0.5,
];

/**
 * Weight of fields in prefix search.  It is safe to change these at any time.
 */
$wgCirrusSearchPrefixWeights = [
	'title' => 10,
	'redirect' => 1,
	'title_asciifolding' => 7,
	'redirect_asciifolding' => 0.7,
];

/**
 * Enable building and using of "all" fields that contain multiple copies of other fields
 * for weighting.  These all fields exist entirely to speed up the full_text query type by
 * baking the weights above into a single field.  This is useful because it drastically
 * reduces the random io to power the query from 14 term queries per term in the query
 * string to 2.  Each term query is potentially one or two disk random io actions.  The
 * reduction isn't strictly 7:1 because we skip file_text in non file namespace (now 6:1)
 * and the near match fields (title and redirect) also kick it, but only once per query.
 * Also don't forget the io from the phrase rescore - this helps with that, but its even
 * more muddy how much.
 * Note setting 'use' to true without having set 'build' to true and performing an in place
 * reindex will cause all searches to find nothing.
 */
$wgCirrusSearchAllFields = [ 'build' => true, 'use' => true ];

/**
 * Should Cirrus use the weighted all fields for the phrase rescore if it is using them
 * for the regular query?
 */
$wgCirrusSearchAllFieldsForRescore = true;

/**
 * The method Cirrus will use to extract the opening section of the text.  Valid values are:
 * * first_heading - Wikipedia style.  Grab the text before the first heading (h1-h6) tag.
 * * none - Do not extract opening text and do not search it.
 */
$wgCirrusSearchBoostOpening = 'first_heading';

/**
 * Weight of fields that match via "near_match" which is ordered.
 */
$wgCirrusSearchNearMatchWeight = 2;

/**
 * Weight of stemmed fields relative to unstemmed.  Meaning if searching for <used>, <use> is only
 * worth this much while <used> is worth 1.  Searching for <"used"> will still only find exact
 * matches.
 */
$wgCirrusSearchStemmedWeight = 0.5;

/**
 * Weight of each namespace relative to NS_MAIN.  If not specified non-talk namespaces default to
 * $wgCirrusSearchDefaultNamespaceWeight.  If not specified talk namespaces default to:
 *   $wgCirrusSearchTalkNamespaceWeight * weightOfCorrespondingNonTalkNamespace
 * The default values below inspired by the configuration used for lsearchd.  Note that _technically_
 * NS_MAIN can be overridden with this then 1 just represents what NS_MAIN would have been....
 * If you override NS_MAIN here then NS_TALK will still default to:
 *   $wgCirrusSearchNamespaceWeights[ NS_MAIN ] * wgCirrusSearchTalkNamespaceWeight
 * You can specify namespace by number or string.  Strings are converted to numbers using the
 * content language including aliases.
 */
$wgCirrusSearchNamespaceWeights = [
	NS_USER => 0.05,
	NS_PROJECT => 0.1,
	NS_MEDIAWIKI => 0.05,
	NS_TEMPLATE => 0.005,
	NS_HELP => 0.1,
];

/**
 * Default weight of non-talks namespaces
 */
$wgCirrusSearchDefaultNamespaceWeight = 0.2;

/**
 * Default weight of a talk namespace relative to its corresponding non-talk namespace.
 */
$wgCirrusSearchTalkNamespaceWeight = 0.25;

/**
 * Default weight of language field for multilingual wikis.
 * 'user' is the weight given to the user's language
 * 'wiki' is the weight given to the wiki's content language
 * If your wiki is only one language you can leave these at 0, otherwise try setting it
 * to something like 5.0 for 'user' and 2.5 for 'wiki'
 */
$wgCirrusSearchLanguageWeight = [
	'user' => 0.0,
	'wiki' => 0.0,
];

/**
 * Portion of an article's score that decays with time since it's last update.  Defaults to 0
 * meaning don't decay the score at all unless prefer-recent: prefixes the query.
 */
$wgCirrusSearchPreferRecentDefaultDecayPortion = 0;

/**
 * Portion of an article's score that decays with time if prefer-recent: prefixes the query but
 * doesn't specify a portion.  Defaults to .6 because that approximates the behavior that
 * wikinews has been using for years.  An article 160 days old is worth about 70% of its new score.
 */
$wgCirrusSearchPreferRecentUnspecifiedDecayPortion = 0.6;

/**
 * Default number of days it takes the portion of an article's score that decays with time since
 * last update to half way decay to use if prefer-recent: prefixes query and doesn't specify a
 * half life or $wgCirrusSearchPreferRecentDefaultDecayPortion is non 0.  Default to 160 because
 * that approximates the behavior that wikinews has been using for years.
 */
$wgCirrusSearchPreferRecentDefaultHalfLife = 160;

/**
 * Configuration parameters passed to more_like_this queries.
 * Note: these values can be configured at runtime by editing the System
 * message cirrussearch-morelikethis-settings
 */
$wgCirrusSearchMoreLikeThisConfig = [
	// Minimum number of documents (per shard) that need a term for it to be considered
	'min_doc_freq' => 2,

	// Maximum number of documents (per shard) that have a term for it to be considered
	// Setting a sufficient high value can be useful to exclude stop words but it depends on the wiki size.
	'max_doc_freq' => null,

	// This is the max number it will collect from input data to build the query
	// This value cannot exceed $wgCirrusSearchMoreLikeThisMaxQueryTermsLimit .
	'max_query_terms' => 25,

	// Minimum TF (number of times the term appears in the input text) for a term to be considered
	// for small fields (title) tf is usually 1 so setting it to 2 will exclude all terms.
	// for large fields (text) this value can help to exclude words that are not related to the subject.
	'min_term_freq' => 2,

	// Minimum length for a word to be considered
	// small words tend to be stop words.
	'min_word_length' => 0,

	// Maximum length for a word to be considered
	// Very long "words" tend to be uncommon, excluding them can help recall but it
	// is highly dependent on the language.
	'max_word_length' => 0,

	// Percent of terms to match
	// High value will increase precision but can prevent small docs to match against large ones
	'minimum_should_match' => '30%',
];

/**
 * Hard limit to the max_query_terms parameter of more like this queries.
 * This prevent running too large queries.
 */
$wgCirrusSearchMoreLikeThisMaxQueryTermsLimit = 100;

/**
 * Set the default field used by the More Like This algorithm
 */
$wgCirrusSearchMoreLikeThisFields = [ 'text' ];

/**
 * List of fields allowed for the more like this queries.
 */
$wgCirrusSearchMoreLikeThisAllowedFields = [
	'title',
	'text',
	'auxiliary_text',
	'opening_text',
	'headings',
];

/**
 * This allows redirecting queries to a separate cluster configured
 * in $wgCirrusSearchClusters. Note that queries can use multiple features, in
 * the case multiple features have overrides the first match wins.
 *
 * Example sending more_like queries to dc-foo and completion to dc-bar:
 *   $wgCirrusSearchClusterOverrides = [
 *     'more_like' => 'dc-foo',
 *     'completion' => 'dc-bar',
 *   ];
 */
$wgCirrusSearchClusterOverrides = [];

/**
 * More like this queries can be quite expensive. Set this to > 0 to cache the
 * results for the specified # of seconds into ObjectCache (memcache, redis, or
 * whatever is configured).
 */
$wgCirrusSearchMoreLikeThisTTL = 0;

/**
 * Fetch external wiki config from the cirrus dump api.
 * Used by cross language and cross project searches.
 * When set to false (default), crossproject configs are approximated
 * crosslanguage configs are fetched from SiteConfiguration
 */
$wgCirrusSearchFetchConfigFromApi = false;

/**
 * CirrusSearch interwiki searching
 * Keys are the interwiki prefix, values are the index to search
 * Results are cached.
 */
$wgCirrusSearchInterwikiSources = [];

/**
 * How long to cache interwiki search results for (in seconds)
 */
$wgCirrusSearchInterwikiCacheTime = 7200;

/**
 * Set the order of crossproject side boxes
 * Possible values:
 * - static: output crossproject results in the order provided
 *   by the interwiki resolver (order set in wgCirrusSearchInterwikiSources
 *   or SiteMatrix)
 * - recall: based on total hits
 */
$wgCirrusSearchCrossProjectOrder = 'static';

/**
 * Profiles to control ordering of blocks of CrossProject searchresults.
 */
$wgCirrusSearchCrossProjectBlockScorerProfiles = [];

/**
 * The seconds Elasticsearch will wait to batch index changes before making
 * them available for search.  Lower values make search more real time but put
 * more load on Elasticsearch.  Defaults to 1 second because that is the default
 * in Elasticsearch.  Changing this will immediately effect wait time on
 * secondary (links) update if those allow waiting (basically if you use Redis
 * for the job queue).  For it to effect Elasticsearch you'll have to rebuild
 * the index.
 */
$wgCirrusSearchRefreshInterval = 1;

/**
 * Delay between when the job is queued for a change and when the job can be
 * unqueued.  The idea is to let the job queue deduplication logic take care
 * of preventing multiple updates for frequently changed pages and to combine
 * many of the secondary changes from template edits into a single update.
 * Note that this does not work with every job queue implementation.  It works
 * with JobQueueRedis but is ignored with JobQueueDB.
 */
$wgCirrusSearchUpdateDelay = [
	'prioritized' => 0,
	'default' => 0,
];

/**
 * List of plugins that Cirrus should ignore when it scans for plugins.  This
 * will cause the plugin not to be used by updateSearchIndexConfig.php and
 * friends.
 */
$wgCirrusSearchBannedPlugins = [];

/**
 * Number of times to instruct Elasticsearch to retry updates that fail on
 * version conflicts.  While we do have a version for each page in mediawiki
 * (the revision timestamp) using it for versioning is a bit tricky because
 * Cirrus uses two pass indexing the first time and sometimes needs to force
 * updates.  This is simpler but theoretically will put more load on
 * Elasticsearch.  At this point, though, we believe the load not to be
 * substantial.
 */
$wgCirrusSearchUpdateConflictRetryCount = 5;

/**
 * Number of characters to include in article fragments.
 */
$wgCirrusSearchFragmentSize = 150;

/**
 * Shard allocation settings. The include/exclude/require top level keys are
 * the type of rule to use, the names should be self explanatory. The values
 * are an array of keys and values of different rules to apply to an index.
 *
 * For example: if you wanted to make sure this index was only allocated to
 * servers matching a specific IP block, you'd do this:
 *    $wgCirrusSearchIndexAllocation['require'] = array( '_ip' => '192.168.1.*' );
 * Or let's say you want to keep an index off a given host:
 *    $wgCirrusSearchIndexAllocation['exclude'] = array( '_host' => 'badserver01' );
 *
 * Note that if you use anything other than the magic values of _ip, _name, _id
 * or _host it requires you to configure the host keys/values on your server(s)
 *
 * http://www.elasticsearch.org/guide/en/elasticsearch/reference/current/index-modules-allocation.html
 */
$wgCirrusSearchIndexAllocation = [
	'include' => [],
	'exclude' => [],
	'require' => [],
];

/**
 * Pool Counter key. If you use the PoolCounter extension, this can help segment your wiki's
 * traffic into separate queues. This has no effect in vanilla MediaWiki and most people can
 * just leave this as it is.
 */
$wgCirrusSearchPoolCounterKey = '_elasticsearch';

/**
 * Merge configuration for the indices.  See
 * http://www.elasticsearch.org/guide/en/elasticsearch/reference/current/index-modules-merge.html
 * for the meanings.
 */
$wgCirrusSearchMergeSettings = [
	'content' => [
		// Aggressive settings to try to keep the content index more optimized
		// because it is searched more frequently.
		'max_merge_at_once' => 5,
		'segments_per_tier' => 5,
		'reclaim_deletes_weight' => 3.0,
		'max_merged_segment' => '25g',
	],
	'general' => [
		// The Elasticsearch defaults for this less frequently searched index.
		'max_merge_at_once' => 10,
		'segments_per_tier' => 10,
		'reclaim_deletes_weight' => 2.0,
		'max_merged_segment' => '5g',
	],
];

/**
 * Whether search events should be logged in the client side.
 */
$wgCirrusSearchEnableSearchLogging = false;

/**
 * Whether elasticsearch queries should be logged on the server side.
 */
$wgCirrusSearchLogElasticRequests = true;

/**
 * When truthy and this value is passed as the cirrusLogElasticRequests query
 * variable $wgCirrusSearchLogElasticRequests will be set to false for that
 * request.
 */
$wgCirrusSearchLogElasticRequestsSecret = false;

// The maximum number of incategory:a|b|c items to OR together.
$wgCirrusSearchMaxIncategoryOptions = 100;

/**
 * The URL of a "Give us your feedback" link to append to search results or
 * something falsy if you don't want to show the link.
 */
$wgCirrusSearchFeedbackLink = false;

/**
 * The maximum amount of time jobs delayed due to frozen indexes can remain
 * in the job queue.
 */
$wgCirrusSearchDropDelayedJobsAfter = 60 * 60 * 24 * 2; // 2 days

/**
 * The initial exponent used when backing off ElasticaWrite jobs. On the first
 * failure the backoff will be either 2^exp or 2^(exp+1). This exponent will
 * be increased to a maximum of exp+4 on repeated failures to run the job.
 */
$wgCirrusSearchWriteBackoffExponent = 6;

/**
 * Configuration of individual a/b tests being run. See CirrusSearch\UserTesting
 * for more information.
 */
$wgCirrusSearchUserTesting = [];

/**
 * Additional completion profiles
 */
$wgCirrusSearchCompletionProfiles = [];

/**
 * Profile for search as you type suggestion (completion suggestion)
 * (see profiles/SuggestProfiles.config.php for more details.)
 */
$wgCirrusSearchCompletionSettings = 'fuzzy';

/**
 * Enable ICU Folding instead of the default ASCII Folding.
 * It allows to cover a wider range of characters when squashing diacritics.
 * see https://www.elastic.co/guide/en/elasticsearch/plugins/current/analysis-icu-folding.html
 * Requires the ICU plugin installed and a recent wmf extra plugin (>= 2.3.4).
 * Set to:
 * - default: let cirrus decides if ICU folding can be enabled according to wiki language
 * - yes: force the use of ICU folding
 * - no: disable ICU folding even if cirrus thinks it can be enabled
 * NOTE: Experimental
 */
$wgCirrusSearchUseIcuFolding = 'default';

/**
 * Set the unicode set filter for ICU folding
 * see http://userguide.icu-project.org/strings/unicodeset
 * e.g. set [^é] to exclude é from icufolding
 */
$wgCirrusSearchICUFoldingUnicodeSetFilter = null;

/**
 * Enable the ICU Tokenizer instead of the standard filter
 * for plain fields.
 * It may be more suited for languages that do not use spaces
 * to break words.
 * Requires the ICU plugin installed
 * Set to:
 * - default: let cirrus decides if the ICU tokenizer can be enabled according to wiki language
 * - yes: force the use of ICU tokenizer
 * - no: disable the ICU tokenizer even if cirrus thinks it can be enabled
 * NOTE: Experimental
 */
$wgCirrusSearchUseIcuTokenizer = 'default';

/**
 * Set the default scoring function to be used by maintenance/updateSuggesterIndex.php
 * @see includes/BuildDocument/SuggestScoring.php for more details about scoring functions
 * NOTE: if you change the scoring method you'll have to rebuild the suggester index.
 */
$wgCirrusSearchCompletionDefaultScore = 'quality';

/**
 * Use the completion suggester as the default implementation for searchSuggestions.
 * You have to build the completion suggester index with the maintenance script
 * updateSuggesterIndex.php. The suggester only supports queries to the main
 * namespace. PrefixSearch will be used in all other cases.
 * Valid values, all unknown values map to 'no':
 *   yes  - Use completion suggester as the default
 *   no   - Don't use completion suggester
 */
$wgCirrusSearchUseCompletionSuggester = 'no';

/**
 * Tell the completion suggest to build and use an
 * extra field built with subphrases suggestions.
 * 2 types of subphrases are supported:
 * - subpages: generate subphrase suggestions based on subpages
 * - anywords: generate subphrase suggestions starting with any words in the
 *   title
 * limit: limits the number of subphrases generated
 */
$wgCirrusSearchCompletionSuggesterSubphrases = [
	'build' => false,
	'use' => false,
	'type' => 'anywords',
	'limit' => 10,
];

/**
 * Use defaultsort as an additional title suggestion
 * Useful in case the title does not start with a representative
 * name ( e.g. Republic of Ireland ) or for names where defaultsort
 * often contains the phrase surname, firstname.
 * NOTE: Experimental
 */
$wgCirrusSearchCompletionSuggesterUseDefaultSort = false;

/**
 * Maximum number of results to ask from the elasticsearch completion
 * api, note that this value will be multiplied by fetch_limit_factor
 * set in Completion profiles (default to 2)
 */
$wgCirrusSearchCompletionSuggesterHardLimit = 50;

/**
 * Try to recycle the completion suggester, if the wiki is small
 * it's certainly better to not re-create the index from scratch
 * since index creation is costly. Recycling the index will prevent
 * elasticsearch from rebalancing shards.
 * On large wikis it's maybe better to create a new index because
 * documents are indexed and optimised with replication disabled
 * reducing the number of disk operation to primary shards only.
 */
$wgCirrusSearchRecycleCompletionSuggesterIndex = true;

/**
 * Enable alternative language search.
 */
$wgCirrusSearchEnableAltLanguage = false;
/**
 * Map of alternative languages and wikis, for search re-try.
 * No defaults since we don't know how people call their other language wikis.
 * Example:
 * $wgCirrusSearchLanguageToWikiMap = array(
 *  'ro' => 'ro',
 *  'de' => 'de',
 *  'ru' => 'ru',
 * );
 * The key is the language name, the value is interwiki link.
 * You will also need to set:
 * $wgCirrusSearchWikiToNameMap['ru'] = 'ruwiki';
 * to link interwiki to the wiki DB name.
 */
$wgCirrusSearchLanguageToWikiMap = [];

/**
 * Map of interwiki link -> wiki name
 * e.g. $wgCirrusSearchWikiToNameMap['ru'] = 'ruwiki';
 * FIXME: we really should already have this information, also we're possibly
 * duplicating $wgCirrusSearchInterwikiSources. This needs to be fixed.
 */
$wgCirrusSearchWikiToNameMap = [];

/**
 * Enable crossproject search.
 * Crossproject works by seaching on so-called sister wikis:
 * Same language, sister project.
 * NOTE: Experimental
 */
$wgCirrusSearchEnableCrossProjectSearch = false;

/**
 * List of crossproject interwiki prefix to ignore
 * when running crossproject search.
 * (only useful when the list of cross projects is
 * obtained via the SiteMatrix extension)
 * Example :
 * $wgCirrusSearchCrossProjectSearchBlackList = [ 'n', 'v' ];
 * In WMF context this would remove wikinews and wikiversity
 * from the list of crossproject displayed in the sidebar
 */
$wgCirrusSearchCrossProjectSearchBlackList = [];

/**
 * List of interwiki prefixes to override.
 * This is only useful when used with SiteMatrix.
 * In some cases a specific wiki may want to override
 * the convention used in SiteMatrix.
 * e.g. on WMF infrastructure this is used to override
 * the interwiki prefix 's' to 'src' on the swedish wikipedia.
 *
 * NOTE: overrides are applied before reading
 * $wgCirrusSearchCrossProjectSearchBlackList and
 * $wgCirrusSearchCrossProjectProfiles
 *
 * Example :
 * $wgCirrusSearchInterwikiPrefixOverrides = [
 *     's' => 'src',
 * ];
 */
$wgCirrusSearchInterwikiPrefixOverrides = [];

/**
 * Override various profiles to use for interwiki searching.
 * Example:
 * $wgCirrusSearchCrossProjectProfiles = [
 *    'v' => [
 *        'ftbuilder' => 'perfield_builder_title_match',
 *        'rescore' => 'wsum_inclinks',
 *    ],
 * ];
 * Will use the perfield_builder_title_match fulttext and wsum_inclinks rescore
 * profile for wikivoyage (WMF context) and the current wiki profile for
 * others.
 */
$wgCirrusSearchCrossProjectProfiles = [];

/**
 * Enables the explore similar feature for search results
 * which adds links to related pages (morelike), categories and
 * languages beside each search result on the SERP.
 */
$wgCirrusExploreSimilarResults = false;

/**
 * The number of results to return in cross-project search
 */
$wgCirrusSearchCrossProjectShowMultimedia = false;

/**
 * The number of results to return in cross-project search
 */
$wgCirrusSearchNumCrossProjectSearchResults = 5;

/**
 * If set to non-empty string, interwiki results will have ?wprov=XYZ parameter added.
 */
$wgCirrusSearchInterwikiProv = false;

/**
 * Custom rescore profiles
 */
$wgCirrusSearchRescoreProfiles = [];

/**
 * Custom rescore function chains
 */
$wgCirrusSearchRescoreFunctionChains = [];

/**
 * Set the full text rescore profile to default.
 * see profile/RescoreProfiles.config.php for more info
 */
$wgCirrusSearchRescoreProfile = 'classic';

/**
 * Set the prefix search rescore profile to default.
 * see profile/RescoreProfiles.config.php for more info
 */
$wgCirrusSearchPrefixSearchRescoreProfile = 'classic';

/**
 * If current wiki has less than this number of results, try to search other language wikis.
 */
$wgCirrusSearchInterwikiThreshold = 3;

/**
 * List of classes to be used as language detectors, implementing
 * CirrusSearch\LanguageDetector\Detector interface.
 * Detectors will be called in the order given until one
 * returns a non-null result. The array key will, currently, only be logged to the
 * UserTesting logs. This is intended to be added to CirrusSearchRequestSet payload
 * as well once schema migration is complete.
 *
 * Two options are built in:
 *
 * CirrusSearch\LanguageDetector\HttpAccept - uses the first language in the
 *  Accept-Language header that is not the current content language.
 * CirrusSearch\LanguageDetector\ElasticSearch - uses the elasticsearch lang-detect plugin
 * CirrusSearch\LanguageDetector\TextCat - uses TextCat library
 */
$wgCirrusSearchLanguageDetectors = [];

/**
 * List of directories where TextCat detector should look for language models
 */
$wgCirrusSearchTextcatModel = [];

/**
 * Configuration for specifying TextCat parameters.
 * Keys are maxNgrams, maxReturnedLanguages, resultsRatio,
 * minInputLength, maxProportion, langBoostScore, and numBoostedLangs.
 * See vendor/wikimedia/textcat/TextCat.php
 */

$wgCirrusSearchTextcatConfig = [];

/**
 * Limit the set of languages detected by Textcat.
 * Useful when some languages in the model have too many false positives, e.g.:
 * $wgCirrusSearchTextcatLanguages = [ 'ar', 'it', 'de' ];
 */

/**
 * Overrides the master timeout on cluster wide actions, such as mapping updates.
 * It may be necessary to increase this on clusters that support a large number
 * of wiki's.
 */
$wgCirrusSearchMasterTimeout = '30s';

/**
 * Activate/Deactivate continuous sanity check.
 * The process will scan and check discrepancies between mysql and
 * elasticsearch for all possible ids in the database.
 * Settings will be automatically chosen according to wiki size (see
 * profiles/SaneitizeProfiles.config.php)
 * The script responsible for pushing sanitization jobs is saneitizeJobs.php.
 * It needs to be scheduled by cron, default settings provided are suited
 * for a bi-hourly schedule (--refresh-freq=7200).
 * Setting $wgCirrusSearchSanityCheck to false will prevent the script from
 * pushing new jobs even if it's still scheduled by cron.
 */
$wgCirrusSearchSanityCheck = true;

/**
 * The base name of indexes used on this wiki. This value must be
 * unique across all wiki's sharing an elasticsearch cluster unless
 * $wgCirrusSearchMultiWikiIndices is set to true.
 * The value '__wikiid__' will be resolved at runtime to wfWikiId().
 */
$wgCirrusSearchIndexBaseName = '__wikiid__';

/**
 * Treat question marks in simple queries as question marks, not
 * wildcard characters, especially at the end of a query. If the
 * query doesn't use insource: and there is no escape character,
 * remove ? from the end of the query, before a word boundary, or
 * everywhere; also de-escape all escaped question marks.
 *
 * Valid values, all unknown values map to 'no':
 *   final - only strip trailing question marks and white space
 *   break - strip non-final question marks followed by a word boundary
 *   all   - strip all question marks (and replace them with spaces)
 *   no    - don't strip question marks
 */
$wgCirrusSearchStripQuestionMarks = 'all';

/**
 * Elasticsearch QueryBuilder to use when when building
 * FullText queries
 */
$wgCirrusSearchFullTextQueryBuilderProfile = 'default';

/**
 * List of additional fulltext query builder profiles
 * see profiles/FullTextQueryBuilderProfiles.config.php
 */
$wgCirrusSearchFullTextQueryBuilderProfiles = [];

/**
 * Transitionary flag for converting between older style
 * doc ids (page ids) to the newer style ids (wikiid|pageid).
 * Changing this from false to true requires first turning
 * this on, then performing an in-place reindex. There may
 * be some duplicate/outdated results while the inplace
 * reindex is running.
 */
$wgCirrusSearchPrefixIds = false;

/**
 * Adds an artificial backend latency in miroseconds.
 * Only useful for testing.
 */
$wgCirrusSearchExtraBackendLatency = 0;

/**
 * Configure default boost-templates
 * Can be overridden on wiki and System messages.
 *
 * $wgCirrusSearchBoostTemplates = [
 * 	'Template:Featured article' => 2.0,
 * ];
 */
$wgCirrusSearchBoostTemplates = [];

/**
 * Disable customization of boost templates on wiki
 * Set to true to disable onwiki config.
 */
$wgCirrusSearchIgnoreOnWikiBoostTemplates = false;

/**
 * CirrusSearch development options:
 * - morelike_collect_titles_from_elastic: first pass collection from elastic
 * - ignore_missing_rev: ignore missing revisions
 * - allow_nuke: Let the tests/jenkins/nukeAllIndexes.php script do its job
 *
 * NOTE: never activate any of these on a production site
 */
$wgCirrusSearchDevelOptions = [];

/**
 * Aliases for file types in filtype: search.
 * Example:
 * $wgCirrusSearchFiletypeAliases = [
 *  'jpg' => 'bitmap',
 *  'image' => 'bitmap',
 *  'document' => 'office',
 * ];
 */
$wgCirrusSearchFiletypeAliases = [];

/**
 * Var to activate some workarounds about specific
 * bugs/quirks found in elasticsearch.
 */
$wgCirrusSearchElasticQuirks = [];

$includes = __DIR__ . "/includes/";
$apiDir = $includes . 'Api/';
$buildDocument = $includes . 'BuildDocument/';
$extraFilterDir = $includes . 'Extra/Filter/';
$jobsDir = $includes . 'Job/';
$maintenanceDir = $includes . 'Maintenance/';
$sanity = $includes . 'Sanity/';
$search = $includes . 'Search/';

/**
 * Classes
 */
require_once __DIR__ . '/autoload.php';

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

/**
 * Hooks
 */
$wgHooks[ 'CirrusSearchBuildDocumentFinishBatch'][] = 'CirrusSearch\BuildDocument\RedirectsAndIncomingLinks::finishBatch';
$wgHooks[ 'CirrusSearchBuildDocumentLinks'][] = 'CirrusSearch\BuildDocument\RedirectsAndIncomingLinks::buildDocument';

$wgHooks[ 'AfterImportPage' ][] = 'CirrusSearch\Hooks::onAfterImportPage';
$wgHooks[ 'APIAfterExecute' ][] = 'CirrusSearch\Hooks::onAPIAfterExecute';
$wgHooks[ 'ApiBeforeMain' ][] = 'CirrusSearch\Hooks::onApiBeforeMain';
$wgHooks[ 'ArticleDelete' ][] = 'CirrusSearch\Hooks::onArticleDelete';
$wgHooks[ 'ArticleDeleteComplete' ][] = 'CirrusSearch\Hooks::onArticleDeleteComplete';
$wgHooks[ 'ArticleRevisionVisibilitySet' ][] = 'CirrusSearch\Hooks::onRevisionDelete';
$wgHooks[ 'ArticleUndelete' ][] = 'CirrusSearch\Hooks::onArticleUndelete';
$wgHooks[ 'BeforeInitialize' ][] = 'CirrusSearch\Hooks::onBeforeInitialize';
$wgHooks[ 'GetPreferences' ][] = 'CirrusSearch\Hooks::onGetPreferences';
$wgHooks[ 'LinksUpdateComplete' ][] = 'CirrusSearch\Hooks::onLinksUpdateCompleted';
$wgHooks[ 'MediaWikiServices' ][] = 'CirrusSearch\Hooks::onMediaWikiServices';
$wgHooks[ 'ResourceLoaderGetConfigVars' ][] = 'CirrusSearch\Hooks::onResourceLoaderGetConfigVars';
$wgHooks[ 'ShowSearchHitTitle' ][] = 'CirrusSearch\Hooks::onShowSearchHitTitle';
$wgHooks[ 'SoftwareInfo' ][] = 'CirrusSearch\Hooks::onSoftwareInfo';
$wgHooks[ 'SpecialSearchResults' ][] = 'CirrusSearch\Hooks::onSpecialSearchResults';
$wgHooks[ 'SpecialSearchResultsAppend' ][] = 'CirrusSearch\Hooks::onSpecialSearchResultsAppend';
$wgHooks[ 'SpecialStatsAddExtra'][] = 'CirrusSearch\Hooks::onSpecialStatsAddExtra';
$wgHooks[ 'TitleMove' ][] = 'CirrusSearch\Hooks::onTitleMove';
$wgHooks[ 'TitleMoveComplete' ][] = 'CirrusSearch\Hooks::onTitleMoveComplete';
$wgHooks[ 'UnitTestsList' ][] = 'CirrusSearch\Hooks::onUnitTestsList';
$wgHooks[ 'UserGetDefaultOptions' ][] = 'CirrusSearch\Hooks::onUserGetDefaultOptions';
$wgHooks[ 'PageContentInsertComplete' ][] = 'CirrusSearch\Hooks::onPageContentInsertComplete';

/**
 * i18n
 */
$wgMessagesDirs['CirrusSearch'] = __DIR__ . '/i18n';

/**
 * Jobs
 */
$wgJobClasses[ 'cirrusSearchDeletePages' ] = 'CirrusSearch\Job\DeletePages';
$wgJobClasses[ 'cirrusSearchIncomingLinkCount' ] = 'CirrusSearch\Job\IncomingLinkCount';
$wgJobClasses[ 'cirrusSearchLinksUpdate' ] = 'CirrusSearch\Job\LinksUpdate';
$wgJobClasses[ 'cirrusSearchLinksUpdatePrioritized' ] = 'CirrusSearch\Job\LinksUpdate';
$wgJobClasses[ 'cirrusSearchMassIndex' ] = 'CirrusSearch\Job\MassIndex';
$wgJobClasses[ 'cirrusSearchOtherIndex' ] = 'CirrusSearch\Job\OtherIndex';
$wgJobClasses[ 'cirrusSearchElasticaWrite' ] = 'CirrusSearch\Job\ElasticaWrite';
$wgJobClasses[ 'cirrusSearchCheckerJob' ] = 'CirrusSearch\Job\CheckerJob';
$wgJobClasses[ 'cirrusSearchDeleteArchive' ] = 'CirrusSearch\Job\DeleteArchive';

/**
 * Actions
 */
$wgActions[ 'cirrusdump' ] = 'CirrusSearch\Dump';

/**
 * API
 */
$wgAPIModules['cirrus-config-dump'] = 'CirrusSearch\Api\ConfigDump';
$wgAPIModules['cirrus-mapping-dump'] = 'CirrusSearch\Api\MappingDump';
$wgAPIModules['cirrus-settings-dump'] = 'CirrusSearch\Api\SettingsDump';
$wgAPIPropModules['cirrusdoc'] = 'CirrusSearch\Api\QueryCirrusDoc';

/**
 * Configs
 */
$wgConfigRegistry['CirrusSearch'] = 'CirrusSearch\SearchConfig::newFromGlobals';

/**
 * JavaScript served to all SERP's
 */
$wgResourceModules += [
	"ext.cirrus.serp" => [
		'scripts' => [
			'resources/ext.cirrus.serp.js',
		],
		'dependencies' => [
			'mediawiki.Uri',
		],
		'styles' => [],
		'messages' => [],
		'remoteExtPath' => 'CirrusSearch',
		'localBasePath' => __DIR__,
		'targets' => [ 'desktop', 'mobile' ],
	],
	"ext.cirrus.explore-similar" => [
		'scripts' => [
			'resources/ext.cirrus.explore-similar.js',
		],
		'dependencies' => [
			'mediawiki.util',
			'mediawiki.api.messages',
			'mediawiki.template.mustache',
		],
		'styles' => [
			'resources/ext.cirrus.explore-similar.less',
		],
		'remoteExtPath' => 'CirrusSearch',
		'localBasePath' => __DIR__,
		'targets' => [ 'desktop' ],
		'messages' => [
			'cirrussearch-explore-similar-related',
			'cirrussearch-explore-similar-categories',
			'cirrussearch-explore-similar-languages',
			'otherlanguages',
			'cirrussearch-explore-similar-related-none',
			'cirrussearch-explore-similar-categories-none',
			'cirrussearch-explore-similar-languages-none',
		]
	]
];

/**
 * Mapping of result types to CirrusSearch classes.
 */
$wgCirrusSearchFieldTypes = [
	SearchIndexField::INDEX_TYPE_TEXT => \CirrusSearch\Search\TextIndexField::class,
	SearchIndexField::INDEX_TYPE_KEYWORD => \CirrusSearch\Search\KeywordIndexField::class,
	SearchIndexField::INDEX_TYPE_INTEGER => \CirrusSearch\Search\IntegerIndexField::class,
	SearchIndexField::INDEX_TYPE_NUMBER => \CirrusSearch\Search\NumberIndexField::class,
	SearchIndexField::INDEX_TYPE_DATETIME => \CirrusSearch\Search\DatetimeIndexField::class,
	SearchIndexField::INDEX_TYPE_BOOL => \CirrusSearch\Search\BooleanIndexField::class,
	SearchIndexField::INDEX_TYPE_NESTED => \CirrusSearch\Search\NestedIndexField::class,
	SearchIndexField::INDEX_TYPE_SHORT_TEXT => \CirrusSearch\Search\ShortTextIndexField::class,
];

/**
 * Customize certain fields with a specific implementation.
 * Useful to apply CirrusSearch specific config to fields
 * controlled by MediaWiki core.
 */
$wgCirrusSearchFieldTypeOverrides = [
	'opening_text' => \CirrusSearch\Search\OpeningTextIndexField::class,
];

/**
 * Custom settings to be provided with index creation. Used for setting
 * slow logs threhsolds and such. Alternatively index templates could
 * be used within elasticsearch.
 *
 * Example:
 *   $wgCirrusSearchExtraIndexSettings = [
 *     'indexing.slowlog.threshold.index.warn' => '10s',
 *     'indexing.slowlog.threshold.index.info' => '5s',
 *     'search.slowlog.threshold.fetch.info' => '1s',
 *     'search.slowlog.threshold.fetch.info' => '800ms',
 *  ]
 */
$wgCirrusSearchExtraIndexSettings = [];

/**
 * Whether to index deleted pages for archiving.
 */
$wgCirrusSearchIndexDeletes = false;
/**
 * Enable archive search.
 */
$wgCirrusSearchEnableArchive = false;

/**
 * Map of configuration variable name to value used to override cirrus config
 * during interleaved full text search. Generally this should *not* be set
 * directly, and instead set via $wgCirrusSearchUserTesting triggers. It is
 * useful to perform Team-Draft interleaved search experiments to compare the
 * performance of two different search configurations.
 */
$wgCirrusSearchInterleaveConfig = null;

/**
 * Maximum number of tokens in a phrase rescore query. Only activated
 * when token_count_router is enabled in $wgCirrusSearchWikimediaExtraPlugin.
 * Queries with more tokens than this skip the phrase rescore portion.
 */
$wgCirrusSearchMaxPhraseTokens = null;

/**
 * URL of the endpoint to look for categories, for deepcat keyword.
 */
$wgCirrusSearchCategoryEndpoint = '';
/**
 * Max depth for deep category query.
 */
$wgCirrusSearchCategoryDepth = 5;
/**
 * Max result count for deep category query.
 */
$wgCirrusSearchCategoryMax = 256;

/**
 * Immediately index new pages, not waiting for LinksUpdate job to finish.
 * This may be desireable if users want new pages to be searchable e.g by title
 * faster than LinkUpdate jobs finish.
 * Set to true to index all pages on wiki, or array of namespaces to index specific namespaces.
 */
$wgCirrusSearchInstantIndexNew = false;
/*
 * Please update docs/settings.txt if you add new values!
 */

$wgServiceWiringFiles[] = __DIR__ . '/includes/ServiceWiring.php';

/**
 * Jenkins configuration required to get all the browser tests passing cleanly.
 *
 * @todo re-enable the code below if/when browser tests are enabled again
 * on Jenkins for Cirrus, and ensure the job name check is specific to
 * CirrusSearch and the entry point is not included for all extension
 * browser tests that happen to have CirrusSearch as a dependency, but
 * not all the other things that the below entry point requires.
 *
 * For now, browser tests are run via Cindy the browser test bot which
 * already directly includes the entry point vs using the check below.
 *
 * Tests are also run for CirrusSearch on beta, but those don't use
 * or need the entry point below.
if ( isset( $wgWikimediaJenkinsCI ) && $wgWikimediaJenkinsCI === true && (
		PHP_SAPI !== 'cli' && // If we're not in the CLI then this is certainly a browser test
		strpos( getenv( 'JOB_NAME' ), 'browsertests-CirrusSearch' ) !== false ) ) {
	require( __DIR__ . '/tests/jenkins/Jenkins.php' );
}
*/
