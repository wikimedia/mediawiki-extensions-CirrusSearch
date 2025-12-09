<?php

namespace CirrusSearch\Api;

use CirrusSearch\Maintenance\ExpectedIndicesBuilder;
use CirrusSearch\Profile\SearchProfileService;
use CirrusSearch\SearchConfig;
use CirrusSearch\UserTestingEngine;
use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiResult;
use MediaWiki\MediaWikiServices;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Dumps CirrusSearch configuration for easy viewing.
 *
 * @license GPL-2.0-or-later
 */
class ConfigDump extends ApiBase {
	use ApiTrait;

	/** @var string[] */
	public static $PUBLICLY_SHAREABLE_CONFIG_VARS = [
		'CirrusSearchDisableUpdate',
		'CirrusSearchConnectionAttempts',
		'CirrusSearchSlowSearch',
		'CirrusSearchUseExperimentalHighlighter',
		'CirrusSearchOptimizeIndexForExperimentalHighlighter',
		'CirrusSearchNamespaceMappings',
		'CirrusSearchExtraIndexes',
		'CirrusSearchExtraIndexClusters',
		'CirrusSearchFetchConfigFromApi',
		'CirrusSearchUpdateShardTimeout',
		'CirrusSearchClientSideUpdateTimeout',
		'CirrusSearchSearchShardTimeout',
		'CirrusSearchClientSizeSearchTimeout',
		'CirrusSearchMaintenanceTimeout',
		'CirrusSearchPrefixSearchStartsWithAnyWord',
		'CirrusSearchPhraseSlop',
		'CirrusSearchPhraseRescoreBoost',
		'CirrusSearchPhraseRescoreWindowSize',
		'CirrusSearchFunctionRescoreWindowSize',
		'CirrusSearchMoreAccurateScoringMode',
		'CirrusSearchPhraseSuggestUseText',
		'CirrusSearchPhraseSuggestUseOpeningText',
		'CirrusSearchIndexedRedirects',
		'CirrusSearchLinkedArticlesToUpdate',
		'CirrusSearchUnlikedArticlesToUpdate',
		'CirrusSearchWeights',
		'CirrusSearchBoostOpening',
		'CirrusSearchNearMatchWeight',
		'CirrusSearchStemmedWeight',
		'CirrusSearchNamespaceWeights',
		'CirrusSearchDefaultNamespaceWeight',
		'CirrusSearchTalkNamespaceWeight',
		'CirrusSearchLanguageWeight',
		'CirrusSearchPreferRecentDefaultDecayPortion',
		'CirrusSearchPreferRecentUnspecifiedDecayPortion',
		'CirrusSearchPreferRecentDefaultHalfLife',
		'CirrusSearchMoreLikeThisConfig',
		'CirrusSearchInterwikiSources',
		'CirrusSearchRefreshInterval',
		'CirrusSearchFragmentSize',
		'CirrusSearchIndexAllocation',
		'CirrusSearchFullTextQueryBuilderProfile',
		'CirrusSearchRescoreProfile',
		'CirrusSearchPrefixSearchRescoreProfile',
		'CirrusSearchSimilarityProfile',
		'CirrusSearchCrossProjectProfiles',
		'CirrusSearchCrossProjectOrder',
		'CirrusSearchCrossProjectSearchBlockList',
		'CirrusSearchExtraIndexBoostTemplates',
		'CirrusSearchEnableCrossProjectSearch',
		'CirrusSearchEnableAltLanguage',
		'CirrusSearchEnableArchive',
		'CirrusSearchUseIcuFolding',
		'CirrusSearchUseIcuTokenizer',
		'CirrusSearchPhraseSuggestProfiles',
		'CirrusSearchCrossProjectBlockScorerProfiles',
		'CirrusSearchSimilarityProfiles',
		'CirrusSearchRescoreFunctionChains',
		'CirrusSearchCompletionProfiles',
		'CirrusSearchCompletionSettings',
		'CirrusSearchCompletionSuggesterUseDefaultSort',
		// All the config below was added when moving this data
		// from CirrusSearch config to a static array in this class
		'CirrusSearchDevelOptions',
		'CirrusSearchPrefixIds',
		'CirrusSearchMoreLikeThisFields',
		'CirrusSearchMoreLikeThisTTL',
		'CirrusSearchFiletypeAliases',
		'CirrusSearchDefaultCluster',
		'CirrusSearchClientSideConnectTimeout',
		'CirrusSearchReplicaGroup',
		'CirrusSearchExtraBackendLatency',
		'CirrusSearchAllowLeadingWildcard',
		'CirrusSearchClientSideSearchTimeout',
		'CirrusSearchStripQuestionMarks',
		'CirrusSearchFullTextQueryBuilderProfiles',
		'CirrusSearchEnableRegex',
		'CirrusSearchWikimediaExtraPlugin',
		'CirrusSearchRegexMaxDeterminizedStates',
		'CirrusSearchMaxIncategoryOptions',
		'CirrusSearchEnablePhraseSuggest',
		'CirrusSearchClusterOverrides',
		'CirrusSearchRescoreProfiles',
		'CirrusSearchRescoreFunctionScoreChains',
		'CirrusSearchNumCrossProjectSearchResults',
		'CirrusSearchLanguageToWikiMap',
		'CirrusSearchWikiToNameMap',
		'CirrusSearchIncLinksAloneW',
		'CirrusSearchIncLinksAloneK',
		'CirrusSearchIncLinksAloneA',
		'CirrusSearchNewCrossProjectPage',
		'CirrusSearchQueryStringMaxDeterminizedStates',
		'CirrusSearchElasticQuirks',
		'CirrusSearchPhraseSuggestMaxErrors',
		'CirrusSearchPhraseSuggestReverseField',
		'CirrusSearchBoostTemplates',
		'CirrusSearchIgnoreOnWikiBoostTemplates',
		'CirrusSearchIndexBaseName',
		'CirrusSearchInterleaveConfig',
		'CirrusSearchMaxPhraseTokens',
		'LanguageCode',
		'ContentNamespaces',
		'NamespacesToBeSearchedDefault',
		'CirrusSearchCategoryDepth',
		'CirrusSearchCategoryMax',
		'CirrusSearchCategoryEndpoint',
		'CirrusSearchFallbackProfile',
		'CirrusSearchFallbackProfiles',
	];

	public function execute() {
		$result = $this->getResult();
		$props = array_flip( $this->extractRequestParams()[ 'prop' ] );
		if ( isset( $props['globals'] ) ) {
			$this->addGlobals( $result );
		}
		if ( isset( $props['namespacemap'] ) ) {
			$this->addConcreteNamespaceMap( $result );
		}
		if ( isset( $props['profiles'] ) ) {
			$this->addProfiles( $result );
		}
		if ( isset( $props['replicagroup'] ) ) {
			$this->addReplicaGroup( $result );
		}
		if ( isset( $props['usertesting'] ) ) {
			$this->addUserTesting( $result );
		}
		if ( isset( $props['expectedindices'] ) ) {
			$this->addExpectedIndices( $result );
		}
	}

	protected function addGlobals( ApiResult $result ): void {
		$config = $this->getConfig();
		foreach ( self::$PUBLICLY_SHAREABLE_CONFIG_VARS as $key ) {
			if ( $config->has( $key ) ) {
				$result->addValue( null, $key, $config->get( $key ) );
			}
		}
	}

	/**
	 * When encoding to json when an array is constructed starting
	 * from zero and adding only sequential keys it will be emit
	 * as a list, instead of a map. Re-order the array so it doesn't
	 * start at zero, unless it's a single element list.
	 *
	 * This does not solve the single element list problem, but in
	 * practice the use case always has multiple values.
	 *
	 * @param array $items
	 * @return array associative array version of source if 2+ elements exist.
	 */
	private function ensureAssociative( array $items ): array {
		if ( isset( $items[0] ) ) {
			$value = $items[0];
			unset( $items[0] );
			$items[0] = $value;
		}
		return $items;
	}

	/**
	 * Include a complete mapping from namespace id to index containing pages.
	 *
	 * Intended for external services/users that need to interact
	 * with elasticsearch or cirrussearch dumps directly.
	 *
	 * @param ApiResult $result Impl to write results to
	 */
	private function addConcreteNamespaceMap( ApiResult $result ) {
		$nsInfo = MediaWikiServices::getInstance()->getNamespaceInfo();
		$conn = $this->getCirrusConnection();
		$indexBaseName = $conn->getConfig()->get( SearchConfig::INDEX_BASE_NAME );
		$items = [];
		foreach ( $nsInfo->getValidNamespaces() as $ns ) {
			$indexSuffix = $conn->getIndexSuffixForNamespace( $ns );
			$indexName = $conn->getIndexName( $indexBaseName, $indexSuffix );
			$items[$ns] = $indexName;
		}
		foreach ( self::ensureAssociative( $items ) as $ns => $indexName ) {
			$result->addValue( 'CirrusSearchConcreteNamespaceMap', $ns, $indexName );
		}
	}

	private function addReplicaGroup( ApiResult $result ) {
		$result->addValue( null, 'CirrusSearchConcreteReplicaGroup',
			$this->getCirrusConnection()->getConfig()->getClusterAssignment()->getCrossClusterName() );
	}

	/**
	 * Profile names and types
	 * @var string[]
	 */
	private static $PROFILES = [
		'CirrusSearchPhraseSuggestProfiles' => SearchProfileService::PHRASE_SUGGESTER,
		'CirrusSearchCrossProjectBlockScorerProfiles' => SearchProfileService::CROSS_PROJECT_BLOCK_SCORER,
		'CirrusSearchSimilarityProfiles' => SearchProfileService::SIMILARITY,
		'CirrusSearchRescoreFunctionChains' => SearchProfileService::RESCORE_FUNCTION_CHAINS,
		'CirrusSearchCompletionProfiles' => SearchProfileService::COMPLETION,
		'CirrusSearchFullTextQueryBuilderProfiles' => SearchProfileService::FT_QUERY_BUILDER,
		'CirrusSearchRescoreProfiles' => SearchProfileService::RESCORE,
	];

	/**
	 * Add data from profiles
	 */
	private function addProfiles( ApiResult $result ) {
		$config = new SearchConfig();
		$profileService = $config->getProfileService();
		foreach ( self::$PROFILES as $var => $profileType ) {
			$data = $profileService->listExposedProfiles( $profileType );
			$result->addValue( null, $var, $data, ApiResult::OVERRIDE );
		}
	}

	/**
	 * @param ApiResult $result
	 * @return void
	 * @throws \CirrusSearch\NoActiveTestException
	 */
	protected function addUserTesting( ApiResult $result ): void {
		// UserTesting only automatically assigns test buckets during web requests.
		// This api call is different from a typical search request though, this is
		// used from non-search pages to find out what bucket to provide to a new
		// autocomplete session.
		$engine = UserTestingEngine::fromConfig( $this->getConfig() );
		$status = $engine->decideTestByAutoenroll();
		$result->addValue( null, 'CirrusSearchActiveUserTest',
			$status->isActive() ? $status->getTrigger() : '' );
	}

	/**
	 * @param ApiResult $result
	 * @return void
	 */
	protected function addExpectedIndices( ApiResult $result ): void {
		$builder = new ExpectedIndicesBuilder( $this->getSearchConfig() );
		$result->addValue( null, 'CirrusSearchExpectedIndices',
			$builder->build( false, null ) );
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return [
			'prop' => [
				ParamValidator::PARAM_DEFAULT => 'globals|namespacemap|profiles|replicagroup',
				ParamValidator::PARAM_TYPE => [
					'globals',
					'namespacemap',
					'profiles',
					'replicagroup',
					'usertesting',
					'expectedindices'
				],
				ParamValidator::PARAM_ISMULTI => true,
			],
		];
	}

	/**
	 * Mark as internal. This isn't meant to be used by normal api users
	 * @return bool
	 */
	public function isInternal() {
		return true;
	}

	/**
	 * @see ApiBase::getExamplesMessages
	 * @return array
	 */
	protected function getExamplesMessages() {
		return [
			'action=cirrus-config-dump' =>
				'apihelp-cirrus-config-dump-example'
		];
	}

}
