<?php

namespace CirrusSearch\Api;

use ApiResult;
use CirrusSearch\Profile\SearchProfileService;
use CirrusSearch\SearchConfig;

/**
 * Dumps CirrusSearch configuration for easy viewing.
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
class ConfigDump extends \ApiBase {
	use ApiTrait;

	public static $WHITE_LIST = [
		'CirrusSearchServers',
		'CirrusSearchConnectionAttempts',
		'CirrusSearchSlowSearch',
		'CirrusSearchUseExperimentalHighlighter',
		'CirrusSearchOptimizeIndexForExperimentalHighlighter',
		'CirrusSearchNamespaceMappings',
		'CirrusSearchExtraIndexes',
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
		'CirrusSearchAllFields',
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
		'CirrusSearchInterwikiCacheTime',
		'CirrusSearchRefreshInterval',
		'CirrusSearchFragmentSize',
		'CirrusSearchMainPageCacheWarmer',
		'CirrusSearchCacheWarmers',
		'CirrusSearchIndexAllocation',
		'CirrusSearchFullTextQueryBuilderProfile',
		'CirrusSearchRescoreProfile',
		'CirrusSearchPrefixSearchRescoreProfile',
		'CirrusSearchSimilarityProfile',
		'CirrusSearchCrossProjectProfiles',
		'CirrusSearchCrossProjectOrder',
		'CirrusSearchCrossProjectSearchBlackList',
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
		// All the config below was added when moving this data
		// from CirrusSearch config to a static array in this class
		'CirrusSearchDevelOptions',
		'CirrusSearchPrefixIds',
		'CirrusSearchMoreLikeThisFields',
		'CirrusSearchMoreLikeThisTTL',
		'CirrusSearchFiletypeAliases',
		'CirrusSearchDefaultCluster',
		'CirrusSearchClientSideConnectTimeout',
		'CirrusSearchClusters',
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
		'CirrusSearchPhraseSuggestSettings',
		'CirrusSearchPhraseSuggestMaxErrors',
		'CirrusSearchPhraseSuggestReverseField',
		'CirrusSearchBoostTemplates',
		'CirrusSearchIgnoreOnWikiBoostTemplates',
		'CirrusSearchAllFieldsForRescore',
		'CirrusSearchIndexBaseName',
		'CirrusSearchInterleaveConfig',
		'CirrusSearchMaxPhraseTokens',
		'LanguageCode',
		'ContentNamespaces',
		'NamespacesToBeSearchedDefault',
		'CirrusSearchCategoryDepth',
		'CirrusSearchCategoryMax',
		'CirrusSearchCategoryEndpoint',
	];

	public function execute() {
		$config = $this->getConfig();
		foreach ( self::$WHITE_LIST as $key ) {
			if ( $config->has( $key ) ) {
				$this->getResult()->addValue( null, $key, $config->get( $key ) );
			}
		}
		$this->addProfiles( $this->getResult() );
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
	 * @param ApiResult $result
	 */
	private function addProfiles( ApiResult $result ) {
		$config = new SearchConfig();
		$profileService = $config->getProfileService();
		foreach ( self::$PROFILES as $var => $profileType ) {
			$data = $profileService->listExposedProfiles( $profileType );
			$this->getResult()->addValue( null, $var, $data, ApiResult::OVERRIDE );
		}
	}

	public function getAllowedParams() {
		return [];
	}

	/**
	 * @deprecated since MediaWiki core 1.25
	 */
	public function getDescription() {
		return 'Dump of CirrusSearch configuration.';
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
