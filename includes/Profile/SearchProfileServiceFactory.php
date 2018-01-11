<?php

namespace CirrusSearch\Profile;

use CirrusSearch\SearchConfig;
use User;
use WebRequest;

/**
 * Default factory to build and prepare search profiles.
 *
 * The factory will load these defaults:
 * <ul>
 * <li>COMPLETION in CONTEXT_DEFAULT
 *  <ul>
 *   <li>default: <i>fuzzy</i></li>
 *   <li>config override: <var>CirrusSearchCompletionSettings</var></li>
 *   <li>user pref override: <var>cirrussearch-pref-completion-profile</var></li>
 *  </ul>
 * </li>
 * <li>CROSS_PROJECT_BLOCK_SCORER in CONTEXT_DEFAULT
 *  <ul>
 *   <li>default: <i>static</i></li>
 *   <li>config override: <var>CirrusSearchCrossProjectOrder</var></li>
 *  </ul>
 * </li>
 * <li>FT_QUERY_BUILDER in CONTEXT_DEFAULT
 *  <ul>
 *   <li>default: <i>default</i></li>
 *   <li>config override: <var>CirrusSearchFullTextQueryBuilderProfile</var></li>
 *   <li>uri param override: <var>cirrusFTQBProfile</var></li>
 *  </ul>
 * </li>
 * <li>PHRASE_SUGGESTER in CONTEXT_DEFAULT
 *  <ul>
 *   <li>default: <i>default</i></li>
 *   <li>config override: <var>CirrusSearchPhraseSuggestSettings</var></li>
 *  </ul>
 * </li>
 * <li>RESCORE in CONTEXT_DEFAULT and CONTEXT_PREFIXSEARCH
 *  <ul>
 *   <li>default (CONTEXT_DEFAULT & CONTEXT_PREFIXSEARCH): <i>classic</i></li>
 *   <li>config override (CONTEXT_DEFAULT): <var>CirrusSearchRescoreProfile</var></li>
 *   <li>config override (CONTEXT_PREFIXSEARCH): <var>CirrusSearchPrefixSearchRescoreProfile</var></li>
 *   <li>uri param override (CONTEXT_PREFIXSEARCH & CONTEXT_PREFIXSEARCH): <var>cirrusRescoreProfile</var></li>
 *  </ul>
 * </li>
 * <li>SANEITIZER
 *  <ul>
 *   <li>default: <i>no defaults (automatically detected based on wiki size)</i></li>
 *  </ul>
 * </li>
 * <li>SIMILARITY in CONTEXT_DEFAULT
 *  <ul>
 *   <li>default: <i>default</i></li>
 *   <li>config override: <var>wgCirrusSearchSimilarityProfile</var></li>
 *  </ul>
 * </li>
 * </ul>
 *
 * <b>NOTE:</b> extensions may load their own repositories and overriders.
 */
class SearchProfileServiceFactory {

	/**
	 * Name of the service declared in MediaWikiServices
	 */
	const SERVICE_NAME = self::class;

	/**
	 * Name of the repositories holding profiles
	 * provided by Cirrus.
	 */
	const CIRRUS_BASE = 'cirrus_base';

	/**
	 * Name of the repositories holding profiles customized
	 * by wiki admin using CirrusSearch config vars.
	 */
	const CIRRUS_CONFIG = 'cirrus_config';

	/**
	 * @param SearchConfig $config
	 * @param WebRequest|null $request
	 * @param User|null $user
	 * @param bool $forceHook force running the hook even if using HashSearchConfig
	 * @return SearchProfileService
	 * @throws \Exception
	 * @throws \FatalError
	 * @throws \MWException
	 */
	public function loadService( SearchConfig $config, WebRequest $request = null, User $user = null, $forceHook = false ) {
		$service = new SearchProfileService( $request, $user );
		$this->loadCrossProjectBlockScorer( $service, $config );
		$this->loadSimilarityProfiles( $service, $config );
		$this->loadRescoreProfiles( $service, $config );
		$this->loadCompletionProfiles( $service, $config );
		$this->loadPhraseSuggesterProfiles( $service, $config );
		$this->loadSaneitizerProfiles( $service );
		$this->loadFullTextQueryProfiles( $service, $config );

		// Register extension profiles only if running on the host wiki.
		// Only cirrus search is aware that we are running a crosswiki search
		// Extensions have no way to know that the profiles they want to declare
		// may be applied to other wikis. Since they may use host wiki config it seems
		// safer not to allow extensions to add extra profiles here.
		// E.g. extension could declare a profile that uses a field that is not available
		// on the target wiki.
		if ( $forceHook || $config->isLocalWiki() ) {
			\Hooks::run( 'CirrusSearchProfileService', [ $service ] );
		}
		$service->freeze();
		return $service;
	}

	/**
	 * @param SearchProfileService $service
	 * @param SearchConfig $config
	 */
	private function loadCrossProjectBlockScorer( SearchProfileService $service, SearchConfig $config ) {
		$service->registerFileRepository( SearchProfileService::CROSS_PROJECT_BLOCK_SCORER,
			self::CIRRUS_BASE, __DIR__ . '/../../profiles/CrossProjectBlockScorerProfiles.config.php' );
		$service->registerRepository( new ConfigProfileRepository( SearchProfileService::CROSS_PROJECT_BLOCK_SCORER,
			self::CIRRUS_CONFIG, 'CirrusSearchCrossProjectBlockScorerProfiles', $config ) );
		$service->registerDefaultProfile( SearchProfileService::CROSS_PROJECT_BLOCK_SCORER,
			SearchProfileService::CONTEXT_DEFAULT, 'static' );
		$service->registerConfigOverride( SearchProfileService::CROSS_PROJECT_BLOCK_SCORER,
			SearchProfileService::CONTEXT_DEFAULT, $config, 'CirrusSearchCrossProjectOrder' );
	}

	/**
	 * @param SearchProfileService $service
	 * @param SearchConfig $config
	 */
	private function loadSimilarityProfiles( SearchProfileService $service, SearchConfig $config ) {
		$service->registerFileRepository( SearchProfileService::SIMILARITY, self::CIRRUS_BASE,
			__DIR__ . '/../../profiles/SimilarityProfiles.config.php' );
		$service->registerRepository( new ConfigProfileRepository( SearchProfileService::SIMILARITY,
			self::CIRRUS_CONFIG, 'CirrusSearchSimilarityProfiles', $config ) );

		$service->registerDefaultProfile( SearchProfileService::SIMILARITY,
			SearchProfileService::CONTEXT_DEFAULT, 'default' );
		$service->registerConfigOverride( SearchProfileService::SIMILARITY,
			SearchProfileService::CONTEXT_DEFAULT, $config, 'CirrusSearchSimilarityProfile' );
	}

	/**
	 * @param SearchProfileService $service
	 * @param SearchConfig $config
	 */
	private function loadRescoreProfiles( SearchProfileService $service, SearchConfig $config ) {
		$service->registerFileRepository( SearchProfileService::RESCORE,
			self::CIRRUS_BASE, __DIR__ . '/../../profiles/RescoreProfiles.config.php' );
		$service->registerRepository( new ConfigProfileRepository( SearchProfileService::RESCORE,
			self::CIRRUS_CONFIG, 'CirrusSearchRescoreProfiles', $config ) );
		$service->registerDefaultProfile( SearchProfileService::RESCORE,
			SearchProfileService::CONTEXT_DEFAULT, 'classic' );
		$service->registerDefaultProfile( SearchProfileService::RESCORE,
			SearchProfileService::CONTEXT_PREFIXSEARCH, 'classic' );

		$service->registerConfigOverride( SearchProfileService::RESCORE,
			SearchProfileService::CONTEXT_DEFAULT, $config, 'CirrusSearchRescoreProfile' );
		$service->registerConfigOverride( SearchProfileService::RESCORE,
			SearchProfileService::CONTEXT_PREFIXSEARCH, $config, 'CirrusSearchPrefixSearchRescoreProfile' );

		$service->registerUriParamOverride( SearchProfileService::RESCORE,
			[ SearchProfileService::CONTEXT_DEFAULT, SearchProfileService::CONTEXT_PREFIXSEARCH ],
			'cirrusRescoreProfile' );

		// function chains
		$service->registerFileRepository( SearchProfileService::RESCORE_FUNCTION_CHAINS,
			self::CIRRUS_BASE, __DIR__ . '/../../profiles/RescoreFunctionChains.config.php' );
		$service->registerRepository( new ConfigProfileRepository( SearchProfileService::RESCORE_FUNCTION_CHAINS,
			self::CIRRUS_CONFIG, 'CirrusSearchRescoreFunctionScoreChains', $config ) );
		// No default profiles for function chains, these profiles are always accessed explicitly
	}

	/**
	 * @param SearchProfileService $service
	 * @param SearchConfig $config
	 */
	private function loadCompletionProfiles( SearchProfileService $service, SearchConfig $config ) {
		$service->registerRepository( CompletionSearchProfileRepository::fromFile( SearchProfileService::COMPLETION,
			self::CIRRUS_BASE, __DIR__ . '/../../profiles/SuggestProfiles.config.php', $config ) );
		$service->registerRepository( CompletionSearchProfileRepository::fromConfig( SearchProfileService::COMPLETION,
			self::CIRRUS_CONFIG, 'CirrusSearchCompletionProfiles', $config ) );
		$service->registerDefaultProfile( SearchProfileService::COMPLETION,
			SearchProfileService::CONTEXT_DEFAULT, 'fuzzy' );
		// XXX: We don't really override the default here
		// Due to the way User preference works we may always end up using
		// the user pref overrides because we initialize default user pref
		// in Hooks::onUserGetDefaultOptions
		$service->registerConfigOverride( SearchProfileService::COMPLETION,
			SearchProfileService::CONTEXT_DEFAULT, $config, 'CirrusSearchCompletionSettings' );
		$service->registerUserPrefOverride( SearchProfileService::COMPLETION,
			SearchProfileService::CONTEXT_DEFAULT, 'cirrussearch-pref-completion-profile' );
	}

	/**
	 * @param SearchProfileService $service
	 * @param SearchConfig $config
	 */
	private function loadPhraseSuggesterProfiles( SearchProfileService $service, SearchConfig $config ) {
		$service->registerRepository( PhraseSuggesterProfileRepoWrapper::fromFile( SearchProfileService::PHRASE_SUGGESTER,
			self::CIRRUS_BASE, __DIR__ . '/../../profiles/PhraseSuggesterProfiles.config.php' ) );

		$service->registerRepository( PhraseSuggesterProfileRepoWrapper::fromConfig( SearchProfileService::PHRASE_SUGGESTER,
			self::CIRRUS_CONFIG, 'CirrusSearchPhraseSuggestProfiles', $config ) );

		$service->registerDefaultProfile( SearchProfileService::PHRASE_SUGGESTER,
			SearchProfileService::CONTEXT_DEFAULT, 'default' );
		$service->registerConfigOverride( SearchProfileService::PHRASE_SUGGESTER,
			SearchProfileService::CONTEXT_DEFAULT, $config, 'CirrusSearchPhraseSuggestSettings' );
	}

	/**
	 * @param SearchProfileService $service
	 */
	private function loadSaneitizerProfiles( SearchProfileService $service ) {
		$service->registerFileRepository( SearchProfileService::SANEITIZER, self::CIRRUS_BASE,
			__DIR__ . '/../../profiles/SaneitizeProfiles.config.php' );
		// no name resolver, profile is automatically chosen based on wiki
	}

	/**
	 * @param SearchProfileService $service
	 * @param SearchConfig $config
	 */
	private function loadFullTextQueryProfiles( SearchProfileService $service, SearchConfig $config ) {
		$service->registerFileRepository( SearchProfileService::FT_QUERY_BUILDER, self::CIRRUS_BASE,
			__DIR__ . '/../../profiles/FullTextQueryBuilderProfiles.config.php' );

		$service->registerRepository( new ConfigProfileRepository( SearchProfileService::FT_QUERY_BUILDER, self::CIRRUS_CONFIG,
			'CirrusSearchFullTextQueryBuilderProfiles', $config ) );

		$service->registerDefaultProfile( SearchProfileService::FT_QUERY_BUILDER,
			SearchProfileService::CONTEXT_DEFAULT, 'default' );
		$service->registerConfigOverride( SearchProfileService::FT_QUERY_BUILDER,
			SearchProfileService::CONTEXT_DEFAULT, $config, 'CirrusSearchFullTextQueryBuilderProfile' );
		$service->registerUriParamOverride( SearchProfileService::FT_QUERY_BUILDER,
			SearchProfileService::CONTEXT_DEFAULT, 'cirrusFTQBProfile' );
	}
}
