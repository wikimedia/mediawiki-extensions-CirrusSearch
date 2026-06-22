<?php

// phpcs:disable Generic.NamingConventions.UpperCaseConstantName.ClassConstantNotUpperCase
// phpcs:disable Generic.Files.LineLength.TooLong
namespace CirrusSearch;

/**
 * A class containing constants representing the names of CirrusSearch configuration
 * variables. These constants can be used in calls to Config::get() (and the related
 * SearchConfig::getElement()/has() accessors), to protect against typos and to make
 * it easier to discover usages of a given setting.
 *
 * Mirrors \MediaWiki\MainConfigNames from MediaWiki core. Most of these
 * configuration variables are declared in this extension's extension.json; a
 * few (grouped at the end of this class) are read by CirrusSearch but are
 * intentionally not declared there, and are listed here so this class remains a
 * complete registry of every CirrusSearch configuration name.
 */
class CirrusConfigNames {
	/**
	 * Name constant for the CirrusSearchDefaultCluster setting, for use with Config::get()
	 */
	public const DefaultCluster = 'CirrusSearchDefaultCluster';

	/**
	 * Name constant for the CirrusSearchDisableUpdate setting, for use with Config::get()
	 */
	public const DisableUpdate = 'CirrusSearchDisableUpdate';

	/**
	 * Name constant for the CirrusSearchClusters setting, for use with Config::get()
	 */
	public const Clusters = 'CirrusSearchClusters';

	/**
	 * Name constant for the CirrusSearchWriteClusters setting, for use with Config::get()
	 */
	public const WriteClusters = 'CirrusSearchWriteClusters';

	/**
	 * Name constant for the CirrusSearchPrivateClusters setting, for use with Config::get()
	 */
	public const PrivateClusters = 'CirrusSearchPrivateClusters';

	/**
	 * Name constant for the CirrusSearchReplicaGroup setting, for use with Config::get()
	 */
	public const ReplicaGroup = 'CirrusSearchReplicaGroup';

	/**
	 * Name constant for the CirrusSearchCrossClusterSearch setting, for use with Config::get()
	 */
	public const CrossClusterSearch = 'CirrusSearchCrossClusterSearch';

	/**
	 * Name constant for the CirrusSearchConnectionAttempts setting, for use with Config::get()
	 */
	public const ConnectionAttempts = 'CirrusSearchConnectionAttempts';

	/**
	 * Name constant for the CirrusSearchShardCount setting, for use with Config::get()
	 */
	public const ShardCount = 'CirrusSearchShardCount';

	/**
	 * Name constant for the CirrusSearchReplicas setting, for use with Config::get()
	 */
	public const Replicas = 'CirrusSearchReplicas';

	/**
	 * Name constant for the CirrusSearchMaxShardsPerNode setting, for use with Config::get()
	 */
	public const MaxShardsPerNode = 'CirrusSearchMaxShardsPerNode';

	/**
	 * Name constant for the CirrusSearchSlowSearch setting, for use with Config::get()
	 */
	public const SlowSearch = 'CirrusSearchSlowSearch';

	/**
	 * Name constant for the CirrusSearchUseExperimentalHighlighter setting, for use with Config::get()
	 */
	public const UseExperimentalHighlighter = 'CirrusSearchUseExperimentalHighlighter';

	/**
	 * Name constant for the CirrusSearchOptimizeIndexForExperimentalHighlighter setting, for use with Config::get()
	 */
	public const OptimizeIndexForExperimentalHighlighter = 'CirrusSearchOptimizeIndexForExperimentalHighlighter';

	/**
	 * Name constant for the CirrusSearchWikimediaExtraPlugin setting, for use with Config::get()
	 */
	public const WikimediaExtraPlugin = 'CirrusSearchWikimediaExtraPlugin';

	/**
	 * Name constant for the CirrusSearchEnableRegex setting, for use with Config::get()
	 */
	public const EnableRegex = 'CirrusSearchEnableRegex';

	/**
	 * Name constant for the CirrusSearchRegexMaxDeterminizedStates setting, for use with Config::get()
	 */
	public const RegexMaxDeterminizedStates = 'CirrusSearchRegexMaxDeterminizedStates';

	/**
	 * Name constant for the CirrusSearchQueryStringMaxDeterminizedStates setting, for use with Config::get()
	 */
	public const QueryStringMaxDeterminizedStates = 'CirrusSearchQueryStringMaxDeterminizedStates';

	/**
	 * Name constant for the CirrusSearchQueryStringMaxWildcards setting, for use with Config::get()
	 */
	public const QueryStringMaxWildcards = 'CirrusSearchQueryStringMaxWildcards';

	/**
	 * Name constant for the CirrusSearchNamespaceMappings setting, for use with Config::get()
	 */
	public const NamespaceMappings = 'CirrusSearchNamespaceMappings';

	/**
	 * Name constant for the CirrusSearchExtraIndexes setting, for use with Config::get()
	 */
	public const ExtraIndexes = 'CirrusSearchExtraIndexes';

	/**
	 * Name constant for the CirrusSearchExtraIndexBoostTemplates setting, for use with Config::get()
	 */
	public const ExtraIndexBoostTemplates = 'CirrusSearchExtraIndexBoostTemplates';

	/**
	 * Name constant for the CirrusSearchUpdateShardTimeout setting, for use with Config::get()
	 */
	public const UpdateShardTimeout = 'CirrusSearchUpdateShardTimeout';

	/**
	 * Name constant for the CirrusSearchClientSideUpdateTimeout setting, for use with Config::get()
	 */
	public const ClientSideUpdateTimeout = 'CirrusSearchClientSideUpdateTimeout';

	/**
	 * Name constant for the CirrusSearchClientSideConnectTimeout setting, for use with Config::get()
	 */
	public const ClientSideConnectTimeout = 'CirrusSearchClientSideConnectTimeout';

	/**
	 * Name constant for the CirrusSearchSearchShardTimeout setting, for use with Config::get()
	 */
	public const SearchShardTimeout = 'CirrusSearchSearchShardTimeout';

	/**
	 * Name constant for the CirrusSearchClientSideSearchTimeout setting, for use with Config::get()
	 */
	public const ClientSideSearchTimeout = 'CirrusSearchClientSideSearchTimeout';

	/**
	 * Name constant for the CirrusSearchMaintenanceTimeout setting, for use with Config::get()
	 */
	public const MaintenanceTimeout = 'CirrusSearchMaintenanceTimeout';

	/**
	 * Name constant for the CirrusSearchPrefixSearchStartsWithAnyWord setting, for use with Config::get()
	 */
	public const PrefixSearchStartsWithAnyWord = 'CirrusSearchPrefixSearchStartsWithAnyWord';

	/**
	 * Name constant for the CirrusSearchPhraseSlop setting, for use with Config::get()
	 */
	public const PhraseSlop = 'CirrusSearchPhraseSlop';

	/**
	 * Name constant for the CirrusSearchPhraseRescoreBoost setting, for use with Config::get()
	 */
	public const PhraseRescoreBoost = 'CirrusSearchPhraseRescoreBoost';

	/**
	 * Name constant for the CirrusSearchPhraseRescoreWindowSize setting, for use with Config::get()
	 */
	public const PhraseRescoreWindowSize = 'CirrusSearchPhraseRescoreWindowSize';

	/**
	 * Name constant for the CirrusSearchFunctionRescoreWindowSize setting, for use with Config::get()
	 */
	public const FunctionRescoreWindowSize = 'CirrusSearchFunctionRescoreWindowSize';

	/**
	 * Name constant for the CirrusSearchMoreAccurateScoringMode setting, for use with Config::get()
	 */
	public const MoreAccurateScoringMode = 'CirrusSearchMoreAccurateScoringMode';

	/**
	 * Name constant for the CirrusSearchFallbackProfile setting, for use with Config::get()
	 */
	public const FallbackProfile = 'CirrusSearchFallbackProfile';

	/**
	 * Name constant for the CirrusSearchFallbackProfiles setting, for use with Config::get()
	 */
	public const FallbackProfiles = 'CirrusSearchFallbackProfiles';

	/**
	 * Name constant for the CirrusSearchEnablePhraseSuggest setting, for use with Config::get()
	 */
	public const EnablePhraseSuggest = 'CirrusSearchEnablePhraseSuggest';

	/**
	 * Name constant for the CirrusSearchPhraseSuggestBuildVariant setting, for use with Config::get()
	 */
	public const PhraseSuggestBuildVariant = 'CirrusSearchPhraseSuggestBuildVariant';

	/**
	 * Name constant for the CirrusSearchPhraseSuggestProfiles setting, for use with Config::get()
	 */
	public const PhraseSuggestProfiles = 'CirrusSearchPhraseSuggestProfiles';

	/**
	 * Name constant for the CirrusSearchPhraseSuggestReverseField setting, for use with Config::get()
	 */
	public const PhraseSuggestReverseField = 'CirrusSearchPhraseSuggestReverseField';

	/**
	 * Name constant for the CirrusSearchRedirectDocuments setting, for use with Config::get()
	 */
	public const RedirectDocuments = 'CirrusSearchRedirectDocuments';

	/**
	 * Name constant for the CirrusSearchPhraseSuggestUseText setting, for use with Config::get()
	 */
	public const PhraseSuggestUseText = 'CirrusSearchPhraseSuggestUseText';

	/**
	 * Name constant for the CirrusSearchPhraseSuggestUseOpeningText setting, for use with Config::get()
	 */
	public const PhraseSuggestUseOpeningText = 'CirrusSearchPhraseSuggestUseOpeningText';

	/**
	 * Name constant for the CirrusSearchAllowLeadingWildcard setting, for use with Config::get()
	 */
	public const AllowLeadingWildcard = 'CirrusSearchAllowLeadingWildcard';

	/**
	 * Name constant for the CirrusSearchIndexFieldsToCleanup setting, for use with Config::get()
	 */
	public const IndexFieldsToCleanup = 'CirrusSearchIndexFieldsToCleanup';

	/**
	 * Name constant for the CirrusSearchIndexWeightedTagsPrefixMap setting, for use with Config::get()
	 */
	public const IndexWeightedTagsPrefixMap = 'CirrusSearchIndexWeightedTagsPrefixMap';

	/**
	 * Name constant for the CirrusSearchIndexedRedirects setting, for use with Config::get()
	 */
	public const IndexedRedirects = 'CirrusSearchIndexedRedirects';

	/**
	 * Name constant for the CirrusSearchLinkedArticlesToUpdate setting, for use with Config::get()
	 */
	public const LinkedArticlesToUpdate = 'CirrusSearchLinkedArticlesToUpdate';

	/**
	 * Name constant for the CirrusSearchUnlinkedArticlesToUpdate setting, for use with Config::get()
	 */
	public const UnlinkedArticlesToUpdate = 'CirrusSearchUnlinkedArticlesToUpdate';

	/**
	 * Name constant for the CirrusSearchSimilarityProfile setting, for use with Config::get()
	 */
	public const SimilarityProfile = 'CirrusSearchSimilarityProfile';

	/**
	 * Name constant for the CirrusSearchSimilarityProfiles setting, for use with Config::get()
	 */
	public const SimilarityProfiles = 'CirrusSearchSimilarityProfiles';

	/**
	 * Name constant for the CirrusSearchWeights setting, for use with Config::get()
	 */
	public const Weights = 'CirrusSearchWeights';

	/**
	 * Name constant for the CirrusSearchPrefixWeights setting, for use with Config::get()
	 */
	public const PrefixWeights = 'CirrusSearchPrefixWeights';

	/**
	 * Name constant for the CirrusSearchNearMatchWeight setting, for use with Config::get()
	 */
	public const NearMatchWeight = 'CirrusSearchNearMatchWeight';

	/**
	 * Name constant for the CirrusSearchStemmedWeight setting, for use with Config::get()
	 */
	public const StemmedWeight = 'CirrusSearchStemmedWeight';

	/**
	 * Name constant for the CirrusSearchNamespaceWeights setting, for use with Config::get()
	 */
	public const NamespaceWeights = 'CirrusSearchNamespaceWeights';

	/**
	 * Name constant for the CirrusSearchDefaultNamespaceWeight setting, for use with Config::get()
	 */
	public const DefaultNamespaceWeight = 'CirrusSearchDefaultNamespaceWeight';

	/**
	 * Name constant for the CirrusSearchTalkNamespaceWeight setting, for use with Config::get()
	 */
	public const TalkNamespaceWeight = 'CirrusSearchTalkNamespaceWeight';

	/**
	 * Name constant for the CirrusSearchLanguageWeight setting, for use with Config::get()
	 */
	public const LanguageWeight = 'CirrusSearchLanguageWeight';

	/**
	 * Name constant for the CirrusSearchPreferRecentDefaultDecayPortion setting, for use with Config::get()
	 */
	public const PreferRecentDefaultDecayPortion = 'CirrusSearchPreferRecentDefaultDecayPortion';

	/**
	 * Name constant for the CirrusSearchPreferRecentUnspecifiedDecayPortion setting, for use with Config::get()
	 */
	public const PreferRecentUnspecifiedDecayPortion = 'CirrusSearchPreferRecentUnspecifiedDecayPortion';

	/**
	 * Name constant for the CirrusSearchPreferRecentDefaultHalfLife setting, for use with Config::get()
	 */
	public const PreferRecentDefaultHalfLife = 'CirrusSearchPreferRecentDefaultHalfLife';

	/**
	 * Name constant for the CirrusSearchMoreLikeThisConfig setting, for use with Config::get()
	 */
	public const MoreLikeThisConfig = 'CirrusSearchMoreLikeThisConfig';

	/**
	 * Name constant for the CirrusSearchMoreLikeThisMaxQueryTermsLimit setting, for use with Config::get()
	 */
	public const MoreLikeThisMaxQueryTermsLimit = 'CirrusSearchMoreLikeThisMaxQueryTermsLimit';

	/**
	 * Name constant for the CirrusSearchMoreLikeThisFields setting, for use with Config::get()
	 */
	public const MoreLikeThisFields = 'CirrusSearchMoreLikeThisFields';

	/**
	 * Name constant for the CirrusSearchMoreLikeThisAllowedFields setting, for use with Config::get()
	 */
	public const MoreLikeThisAllowedFields = 'CirrusSearchMoreLikeThisAllowedFields';

	/**
	 * Name constant for the CirrusSearchClusterOverrides setting, for use with Config::get()
	 */
	public const ClusterOverrides = 'CirrusSearchClusterOverrides';

	/**
	 * Name constant for the CirrusSearchMoreLikeThisTTL setting, for use with Config::get()
	 */
	public const MoreLikeThisTTL = 'CirrusSearchMoreLikeThisTTL';

	/**
	 * Name constant for the CirrusSearchFetchConfigFromApi setting, for use with Config::get()
	 */
	public const FetchConfigFromApi = 'CirrusSearchFetchConfigFromApi';

	/**
	 * Name constant for the CirrusSearchInterwikiSources setting, for use with Config::get()
	 */
	public const InterwikiSources = 'CirrusSearchInterwikiSources';

	/**
	 * Name constant for the CirrusSearchCrossProjectOrder setting, for use with Config::get()
	 */
	public const CrossProjectOrder = 'CirrusSearchCrossProjectOrder';

	/**
	 * Name constant for the CirrusSearchCrossProjectBlockScorerProfiles setting, for use with Config::get()
	 */
	public const CrossProjectBlockScorerProfiles = 'CirrusSearchCrossProjectBlockScorerProfiles';

	/**
	 * Name constant for the CirrusSearchInterwikiHTTPTimeout setting, for use with Config::get()
	 */
	public const InterwikiHTTPTimeout = 'CirrusSearchInterwikiHTTPTimeout';

	/**
	 * Name constant for the CirrusSearchInterwikiHTTPConnectTimeout setting, for use with Config::get()
	 */
	public const InterwikiHTTPConnectTimeout = 'CirrusSearchInterwikiHTTPConnectTimeout';

	/**
	 * Name constant for the CirrusSearchRefreshInterval setting, for use with Config::get()
	 */
	public const RefreshInterval = 'CirrusSearchRefreshInterval';

	/**
	 * Name constant for the CirrusSearchUpdateDelay setting, for use with Config::get()
	 */
	public const UpdateDelay = 'CirrusSearchUpdateDelay';

	/**
	 * Name constant for the CirrusSearchBannedPlugins setting, for use with Config::get()
	 */
	public const BannedPlugins = 'CirrusSearchBannedPlugins';

	/**
	 * Name constant for the CirrusSearchUpdateConflictRetryCount setting, for use with Config::get()
	 */
	public const UpdateConflictRetryCount = 'CirrusSearchUpdateConflictRetryCount';

	/**
	 * Name constant for the CirrusSearchFragmentSize setting, for use with Config::get()
	 */
	public const FragmentSize = 'CirrusSearchFragmentSize';

	/**
	 * Name constant for the CirrusSearchIndexAllocation setting, for use with Config::get()
	 */
	public const IndexAllocation = 'CirrusSearchIndexAllocation';

	/**
	 * Name constant for the CirrusSearchPoolCounterKey setting, for use with Config::get()
	 */
	public const PoolCounterKey = 'CirrusSearchPoolCounterKey';

	/**
	 * Name constant for the CirrusSearchMergeSettings setting, for use with Config::get()
	 */
	public const MergeSettings = 'CirrusSearchMergeSettings';

	/**
	 * Name constant for the CirrusSearchLogElasticRequests setting, for use with Config::get()
	 */
	public const LogElasticRequests = 'CirrusSearchLogElasticRequests';

	/**
	 * Name constant for the CirrusSearchLogElasticRequestsSecret setting, for use with Config::get()
	 */
	public const LogElasticRequestsSecret = 'CirrusSearchLogElasticRequestsSecret';

	/**
	 * Name constant for the CirrusSearchMaxFullTextQueryLength setting, for use with Config::get()
	 */
	public const MaxFullTextQueryLength = 'CirrusSearchMaxFullTextQueryLength';

	/**
	 * Name constant for the CirrusSearchMaxIncategoryOptions setting, for use with Config::get()
	 */
	public const MaxIncategoryOptions = 'CirrusSearchMaxIncategoryOptions';

	/**
	 * Name constant for the CirrusSearchFeedbackLink setting, for use with Config::get()
	 */
	public const FeedbackLink = 'CirrusSearchFeedbackLink';

	/**
	 * Name constant for the CirrusSearchWriteBackoffExponent setting, for use with Config::get()
	 */
	public const WriteBackoffExponent = 'CirrusSearchWriteBackoffExponent';

	/**
	 * Name constant for the CirrusSearchUserTesting setting, for use with Config::get()
	 */
	public const UserTesting = 'CirrusSearchUserTesting';

	/**
	 * Name constant for the CirrusSearchActiveTest setting, for use with Config::get()
	 */
	public const ActiveTest = 'CirrusSearchActiveTest';

	/**
	 * Name constant for the CirrusSearchCompletionProfiles setting, for use with Config::get()
	 */
	public const CompletionProfiles = 'CirrusSearchCompletionProfiles';

	/**
	 * Name constant for the CirrusSearchCompletionSettings setting, for use with Config::get()
	 */
	public const CompletionSettings = 'CirrusSearchCompletionSettings';

	/**
	 * Name constant for the CirrusSearchCompletionPlainTokenizer setting, for use with Config::get()
	 */
	public const CompletionPlainTokenizer = 'CirrusSearchCompletionPlainTokenizer';

	/**
	 * Name constant for the CirrusSearchUseIcuFolding setting, for use with Config::get()
	 */
	public const UseIcuFolding = 'CirrusSearchUseIcuFolding';

	/**
	 * Name constant for the CirrusSearchICUNormalizationUnicodeSetFilter setting, for use with Config::get()
	 */
	public const ICUNormalizationUnicodeSetFilter = 'CirrusSearchICUNormalizationUnicodeSetFilter';

	/**
	 * Name constant for the CirrusSearchICUFoldingUnicodeSetFilter setting, for use with Config::get()
	 */
	public const ICUFoldingUnicodeSetFilter = 'CirrusSearchICUFoldingUnicodeSetFilter';

	/**
	 * Name constant for the CirrusSearchUseIcuTokenizer setting, for use with Config::get()
	 */
	public const UseIcuTokenizer = 'CirrusSearchUseIcuTokenizer';

	/**
	 * Name constant for the CirrusSearchCompletionDefaultScore setting, for use with Config::get()
	 */
	public const CompletionDefaultScore = 'CirrusSearchCompletionDefaultScore';

	/**
	 * Name constant for the CirrusSearchUseCompletionSuggester setting, for use with Config::get()
	 */
	public const UseCompletionSuggester = 'CirrusSearchUseCompletionSuggester';

	/**
	 * Name constant for the CirrusSearchCompletionSuggesterSubphrases setting, for use with Config::get()
	 */
	public const CompletionSuggesterSubphrases = 'CirrusSearchCompletionSuggesterSubphrases';

	/**
	 * Name constant for the CirrusSearchCompletionSuggesterUseDefaultSort setting, for use with Config::get()
	 */
	public const CompletionSuggesterUseDefaultSort = 'CirrusSearchCompletionSuggesterUseDefaultSort';

	/**
	 * Name constant for the CirrusSearchCompletionSuggesterHardLimit setting, for use with Config::get()
	 */
	public const CompletionSuggesterHardLimit = 'CirrusSearchCompletionSuggesterHardLimit';

	/**
	 * Name constant for the CirrusSearchRecycleCompletionSuggesterIndex setting, for use with Config::get()
	 */
	public const RecycleCompletionSuggesterIndex = 'CirrusSearchRecycleCompletionSuggesterIndex';

	/**
	 * Name constant for the CirrusSearchEnableAltLanguage setting, for use with Config::get()
	 */
	public const EnableAltLanguage = 'CirrusSearchEnableAltLanguage';

	/**
	 * Name constant for the CirrusSearchLanguageToWikiMap setting, for use with Config::get()
	 */
	public const LanguageToWikiMap = 'CirrusSearchLanguageToWikiMap';

	/**
	 * Name constant for the CirrusSearchWikiToNameMap setting, for use with Config::get()
	 */
	public const WikiToNameMap = 'CirrusSearchWikiToNameMap';

	/**
	 * Name constant for the CirrusSearchEnableCrossProjectSearch setting, for use with Config::get()
	 */
	public const EnableCrossProjectSearch = 'CirrusSearchEnableCrossProjectSearch';

	/**
	 * Name constant for the CirrusSearchCrossProjectSearchBlockList setting, for use with Config::get()
	 */
	public const CrossProjectSearchBlockList = 'CirrusSearchCrossProjectSearchBlockList';

	/**
	 * Name constant for the CirrusSearchInterwikiPrefixOverrides setting, for use with Config::get()
	 */
	public const InterwikiPrefixOverrides = 'CirrusSearchInterwikiPrefixOverrides';

	/**
	 * Name constant for the CirrusSearchCrossProjectProfiles setting, for use with Config::get()
	 */
	public const CrossProjectProfiles = 'CirrusSearchCrossProjectProfiles';

	/**
	 * Name constant for the CirrusSearchCrossProjectShowMultimedia setting, for use with Config::get()
	 */
	public const CrossProjectShowMultimedia = 'CirrusSearchCrossProjectShowMultimedia';

	/**
	 * Name constant for the CirrusSearchNumCrossProjectSearchResults setting, for use with Config::get()
	 */
	public const NumCrossProjectSearchResults = 'CirrusSearchNumCrossProjectSearchResults';

	/**
	 * Name constant for the CirrusSearchInterwikiProv setting, for use with Config::get()
	 */
	public const InterwikiProv = 'CirrusSearchInterwikiProv';

	/**
	 * Name constant for the CirrusSearchRescoreProfiles setting, for use with Config::get()
	 */
	public const RescoreProfiles = 'CirrusSearchRescoreProfiles';

	/**
	 * Name constant for the CirrusSearchRescoreFunctionChains setting, for use with Config::get()
	 */
	public const RescoreFunctionChains = 'CirrusSearchRescoreFunctionChains';

	/**
	 * Name constant for the CirrusSearchRescoreProfile setting, for use with Config::get()
	 */
	public const RescoreProfile = 'CirrusSearchRescoreProfile';

	/**
	 * Name constant for the CirrusSearchPrefixSearchRescoreProfile setting, for use with Config::get()
	 */
	public const PrefixSearchRescoreProfile = 'CirrusSearchPrefixSearchRescoreProfile';

	/**
	 * Name constant for the CirrusSearchInterwikiThreshold setting, for use with Config::get()
	 */
	public const InterwikiThreshold = 'CirrusSearchInterwikiThreshold';

	/**
	 * Name constant for the CirrusSearchLanguageDetectors setting, for use with Config::get()
	 */
	public const LanguageDetectors = 'CirrusSearchLanguageDetectors';

	/**
	 * Name constant for the CirrusSearchTextcatModel setting, for use with Config::get()
	 */
	public const TextcatModel = 'CirrusSearchTextcatModel';

	/**
	 * Name constant for the CirrusSearchTextcatConfig setting, for use with Config::get()
	 */
	public const TextcatConfig = 'CirrusSearchTextcatConfig';

	/**
	 * Name constant for the CirrusSearchMasterTimeout setting, for use with Config::get()
	 */
	public const MasterTimeout = 'CirrusSearchMasterTimeout';

	/**
	 * Name constant for the CirrusSearchIndexBaseName setting, for use with Config::get()
	 */
	public const IndexBaseName = 'CirrusSearchIndexBaseName';

	/**
	 * Name constant for the CirrusSearchStripQuestionMarks setting, for use with Config::get()
	 */
	public const StripQuestionMarks = 'CirrusSearchStripQuestionMarks';

	/**
	 * Name constant for the CirrusSearchFullTextQueryBuilderProfile setting, for use with Config::get()
	 */
	public const FullTextQueryBuilderProfile = 'CirrusSearchFullTextQueryBuilderProfile';

	/**
	 * Name constant for the CirrusSearchFullTextQueryBuilderProfiles setting, for use with Config::get()
	 */
	public const FullTextQueryBuilderProfiles = 'CirrusSearchFullTextQueryBuilderProfiles';

	/**
	 * Name constant for the CirrusSearchPrefixIds setting, for use with Config::get()
	 */
	public const PrefixIds = 'CirrusSearchPrefixIds';

	/**
	 * Name constant for the CirrusSearchExtraBackendLatency setting, for use with Config::get()
	 */
	public const ExtraBackendLatency = 'CirrusSearchExtraBackendLatency';

	/**
	 * Name constant for the CirrusSearchBoostTemplates setting, for use with Config::get()
	 */
	public const BoostTemplates = 'CirrusSearchBoostTemplates';

	/**
	 * Name constant for the CirrusSearchIgnoreOnWikiBoostTemplates setting, for use with Config::get()
	 */
	public const IgnoreOnWikiBoostTemplates = 'CirrusSearchIgnoreOnWikiBoostTemplates';

	/**
	 * Name constant for the CirrusSearchDevelOptions setting, for use with Config::get()
	 */
	public const DevelOptions = 'CirrusSearchDevelOptions';

	/**
	 * Name constant for the CirrusSearchFiletypeAliases setting, for use with Config::get()
	 */
	public const FiletypeAliases = 'CirrusSearchFiletypeAliases';

	/**
	 * Name constant for the CirrusSearchDocumentSizeLimiterProfile setting, for use with Config::get()
	 */
	public const DocumentSizeLimiterProfile = 'CirrusSearchDocumentSizeLimiterProfile';

	/**
	 * Name constant for the CirrusSearchDocumentSizeLimiterProfiles setting, for use with Config::get()
	 */
	public const DocumentSizeLimiterProfiles = 'CirrusSearchDocumentSizeLimiterProfiles';

	/**
	 * Name constant for the CirrusSearchMaxFileTextLength setting, for use with Config::get()
	 */
	public const MaxFileTextLength = 'CirrusSearchMaxFileTextLength';

	/**
	 * Name constant for the CirrusSearchElasticQuirks setting, for use with Config::get()
	 */
	public const ElasticQuirks = 'CirrusSearchElasticQuirks';

	/**
	 * Name constant for the CirrusSearchExtraIndexSettings setting, for use with Config::get()
	 */
	public const ExtraIndexSettings = 'CirrusSearchExtraIndexSettings';

	/**
	 * Name constant for the CirrusSearchIndexDeletes setting, for use with Config::get()
	 */
	public const IndexDeletes = 'CirrusSearchIndexDeletes';

	/**
	 * Name constant for the CirrusSearchEnableArchive setting, for use with Config::get()
	 */
	public const EnableArchive = 'CirrusSearchEnableArchive';

	/**
	 * Name constant for the CirrusSearchInterleaveConfig setting, for use with Config::get()
	 */
	public const InterleaveConfig = 'CirrusSearchInterleaveConfig';

	/**
	 * Name constant for the CirrusSearchMaxPhraseTokens setting, for use with Config::get()
	 */
	public const MaxPhraseTokens = 'CirrusSearchMaxPhraseTokens';

	/**
	 * Name constant for the CirrusSearchCategoryEndpoint setting, for use with Config::get()
	 */
	public const CategoryEndpoint = 'CirrusSearchCategoryEndpoint';

	/**
	 * Name constant for the CirrusSearchCategoryDepth setting, for use with Config::get()
	 */
	public const CategoryDepth = 'CirrusSearchCategoryDepth';

	/**
	 * Name constant for the CirrusSearchCategoryMax setting, for use with Config::get()
	 */
	public const CategoryMax = 'CirrusSearchCategoryMax';

	/**
	 * Name constant for the CirrusSearchNamespaceResolutionMethod setting, for use with Config::get()
	 */
	public const NamespaceResolutionMethod = 'CirrusSearchNamespaceResolutionMethod';

	/**
	 * Name constant for the CirrusSearchNamespaceMatcherProfiles setting, for use with Config::get()
	 */
	public const NamespaceMatcherProfiles = 'CirrusSearchNamespaceMatcherProfiles';

	/**
	 * Name constant for the CirrusSearchWeightedTags setting, for use with Config::get()
	 */
	public const WeightedTags = 'CirrusSearchWeightedTags';

	/**
	 * Name constant for the CirrusSearchCompletionBannedPageIds setting, for use with Config::get()
	 */
	public const CompletionBannedPageIds = 'CirrusSearchCompletionBannedPageIds';

	/**
	 * Name constant for the CirrusSearchAutomationCIDRs setting, for use with Config::get()
	 */
	public const AutomationCIDRs = 'CirrusSearchAutomationCIDRs';

	/**
	 * Name constant for the CirrusSearchAutomationHeaderRegexes setting, for use with Config::get()
	 */
	public const AutomationHeaderRegexes = 'CirrusSearchAutomationHeaderRegexes';

	/**
	 * Name constant for the CirrusSearchCustomPageFields setting, for use with Config::get()
	 */
	public const CustomPageFields = 'CirrusSearchCustomPageFields';

	/**
	 * Name constant for the CirrusSearchExtraFieldsInSearchResults setting, for use with Config::get()
	 */
	public const ExtraFieldsInSearchResults = 'CirrusSearchExtraFieldsInSearchResults';

	/**
	 * Name constant for the CirrusSearchEnableIncomingLinkCounting setting, for use with Config::get()
	 */
	public const EnableIncomingLinkCounting = 'CirrusSearchEnableIncomingLinkCounting';

	/**
	 * Name constant for the CirrusSearchDeduplicateAnalysis setting, for use with Config::get()
	 */
	public const DeduplicateAnalysis = 'CirrusSearchDeduplicateAnalysis';

	/**
	 * Name constant for the CirrusSearchUseEventBusBridge setting, for use with Config::get()
	 */
	public const UseEventBusBridge = 'CirrusSearchUseEventBusBridge';

	/**
	 * Name constant for the CirrusSearchDeduplicateInQuery setting, for use with Config::get()
	 */
	public const DeduplicateInQuery = 'CirrusSearchDeduplicateInQuery';

	/**
	 * Name constant for the CirrusSearchDeduplicateInMemory setting, for use with Config::get()
	 */
	public const DeduplicateInMemory = 'CirrusSearchDeduplicateInMemory';

	/**
	 * Name constant for the CirrusSearchNaturalTitleSort setting, for use with Config::get()
	 */
	public const NaturalTitleSort = 'CirrusSearchNaturalTitleSort';

	/**
	 * Name constant for the CirrusSearchEnableEventBusWeightedTags setting, for use with Config::get()
	 */
	public const EnableEventBusWeightedTags = 'CirrusSearchEnableEventBusWeightedTags';

	/**
	 * Name constant for the CirrusSearchMustTrackTotalHits setting, for use with Config::get()
	 */
	public const MustTrackTotalHits = 'CirrusSearchMustTrackTotalHits';

	/**
	 * Name constant for the CirrusSearchLanguageKeywordExtraFields setting, for use with Config::get()
	 */
	public const LanguageKeywordExtraFields = 'CirrusSearchLanguageKeywordExtraFields';

	/**
	 * Name constant for the CirrusSearchManagedClusters setting, for use with Config::get()
	 */
	public const ManagedClusters = 'CirrusSearchManagedClusters';

	/**
	 * Name constant for the CirrusSearchCompletionSuggesterUseAltIndexId setting, for use with Config::get()
	 */
	public const CompletionSuggesterUseAltIndexId = 'CirrusSearchCompletionSuggesterUseAltIndexId';

	/**
	 * Name constant for the CirrusSearchAlternativeIndices setting, for use with Config::get()
	 */
	public const AlternativeIndices = 'CirrusSearchAlternativeIndices';

	/**
	 * Name constant for the CirrusSearchStreamingUpdaterUsername setting, for use with Config::get()
	 */
	public const StreamingUpdaterUsername = 'CirrusSearchStreamingUpdaterUsername';

	/**
	 * Name constant for the CirrusSearchSecondTryProfiles setting, for use with Config::get()
	 */
	public const SecondTryProfiles = 'CirrusSearchSecondTryProfiles';

	/**
	 * Name constant for the CirrusSearchCompletionUseSecondTryProfile setting, for use with Config::get()
	 */
	public const CompletionUseSecondTryProfile = 'CirrusSearchCompletionUseSecondTryProfile';

	/**
	 * Name constant for the CirrusSearchCategoriesClientCacheTTL setting, for use with Config::get()
	 */
	public const CategoriesClientCacheTTL = 'CirrusSearchCategoriesClientCacheTTL';

	/**
	 * Name constant for the CirrusSearchDefaultSemanticProfile setting, for use with Config::get()
	 */
	public const DefaultSemanticProfile = 'CirrusSearchDefaultSemanticProfile';

	// The following configuration variables are read by CirrusSearch but are NOT
	// declared in extension.json (they have no registered default, are computed at
	// runtime, or are optional profile overrides). They are listed here so this
	// class remains a complete registry of every CirrusSearch configuration name.

	/**
	 * Name constant for the CirrusSearchServers setting, for use with Config::get()
	 */
	public const Servers = 'CirrusSearchServers';

	/**
	 * Name constant for the CirrusSearchTextcatLanguages setting, for use with Config::get()
	 */
	public const TextcatLanguages = 'CirrusSearchTextcatLanguages';

	/**
	 * Name constant for the CirrusSearchOptimizeForExperimentalHighlighter setting, for use with Config::get()
	 */
	public const OptimizeForExperimentalHighlighter = 'CirrusSearchOptimizeForExperimentalHighlighter';

	/**
	 * Name constant for the CirrusSearchConcreteNamespaceMap setting, for use with Config::get()
	 */
	public const ConcreteNamespaceMap = 'CirrusSearchConcreteNamespaceMap';

	/**
	 * Name constant for the CirrusSearchIndexLookupFallbackProfiles setting, for use with Config::get()
	 */
	public const IndexLookupFallbackProfiles = 'CirrusSearchIndexLookupFallbackProfiles';

	/**
	 * Name constant for the CirrusSearchRescoreFunctionScoreChains setting, for use with Config::get()
	 */
	public const RescoreFunctionScoreChains = 'CirrusSearchRescoreFunctionScoreChains';

	// Optional per-function rescore overrides, referenced as 'config_override' values in
	// profiles/RescoreFunctionChains.config.php and read dynamically in
	// Search\Rescore\FunctionScoreBuilder.

	/**
	 * Name constant for the CirrusSearchIncLinksA setting, for use with Config::get()
	 */
	public const IncLinksA = 'CirrusSearchIncLinksA';

	/**
	 * Name constant for the CirrusSearchIncLinksK setting, for use with Config::get()
	 */
	public const IncLinksK = 'CirrusSearchIncLinksK';

	/**
	 * Name constant for the CirrusSearchIncLinksW setting, for use with Config::get()
	 */
	public const IncLinksW = 'CirrusSearchIncLinksW';

	/**
	 * Name constant for the CirrusSearchIncLinksAloneA setting, for use with Config::get()
	 */
	public const IncLinksAloneA = 'CirrusSearchIncLinksAloneA';

	/**
	 * Name constant for the CirrusSearchIncLinksAloneK setting, for use with Config::get()
	 */
	public const IncLinksAloneK = 'CirrusSearchIncLinksAloneK';

	/**
	 * Name constant for the CirrusSearchIncLinksAloneW setting, for use with Config::get()
	 */
	public const IncLinksAloneW = 'CirrusSearchIncLinksAloneW';

	/**
	 * Name constant for the CirrusSearchPageViewsA setting, for use with Config::get()
	 */
	public const PageViewsA = 'CirrusSearchPageViewsA';

	/**
	 * Name constant for the CirrusSearchPageViewsK setting, for use with Config::get()
	 */
	public const PageViewsK = 'CirrusSearchPageViewsK';

	/**
	 * Name constant for the CirrusSearchPageViewsW setting, for use with Config::get()
	 */
	public const PageViewsW = 'CirrusSearchPageViewsW';
}
