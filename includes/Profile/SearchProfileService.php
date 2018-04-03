<?php

namespace CirrusSearch\Profile;

use \Config;
use \RequestContext;
use \User;
use \WebRequest;

/**
 * Service to manage and access search profiles.
 * Search profiles are arranged by type identified by a string constant:
 * - COMPLETION: profiles used for autocomplete search when running the completion suggester
 * - CROSS_PROJECT_BLOCK_SCORER: used when reordering blocks of crossproject search results
 * - FT_QUERY_BUILDER: used when building fulltext search queries
 * - PHRASE_SUGGESTER: Controls the behavior of the phrase suggester (did you mean suggestions)
 * - RESCORE: Controls how elasticsearch rescore queries are built
 * - RESCORE_FUNCTION_CHAINS: Controls the list of functions used by a rescore profile
 * - SANEITIZER: Controls the saneitizer
 * - SIMILARITY: Defines similarity profiles used when building the index
 *
 * Multiple repository per type can be declared, in general we have:
 * - the cirrus_base repository holding the default profiles contained in cirrus code
 * - the cirrus_config repository holding the profiles customized using $wgCirrusSearch config vars.
 *
 * The service is bound to a SearchConfig instance which means that the profiles may vary depending
 * on the SearchConfig being used. The cirrus_base repository will always hold the same set of
 * profiles but the cirrus_config may change according to SearchConfig content.
 *
 * The service is also responsible for determining the name of the default profile for a given context.
 * The profile context is a notion introduced to allow using the same profile for multiple purposes.
 * For example the rescore profiles may be used for different kind of queries (fulltext vs prefixsearch).
 * While they share the same set of profiles we may prefer to use different defaults depending on the
 * type of the query. The profile context allows to distinguish between these use cases.
 *
 * Then in order to customize the default profile the service allows to define a list of "overriders":
 * - ConfigSearchProfileOverride: overrides the default profile by reading a config var
 * - UriParamSearchProfileOverride: overrides the default profile by inspecting the URI params
 * - UserPrefSearchProfileOverride: overrides the default profile by inspecting the user prefs
 */
class SearchProfileService {

	/**
	 * Profile type for ordering crossproject result blocks
	 */
	const CROSS_PROJECT_BLOCK_SCORER = 'crossproject_block_scorer';

	/**
	 * Profile type for similarity configuration
	 * Used when building the indices
	 */
	const SIMILARITY = 'similarity';

	/**
	 * Profile type for rescoring components
	 * Used at query when building elastic queries
	 * @see \CirrusSearch\Search\Rescore\RescoreBuilder
	 */
	const RESCORE = 'rescore';

	/**
	 * Profile type used to build function chains
	 * Used at query time by rescore builders
	 * @see \CirrusSearch\Search\Rescore\RescoreBuilder
	 */
	const RESCORE_FUNCTION_CHAINS = 'rescore_function_chains';

	/**
	 * Profile type used by the completion suggester
	 * @see \CirrusSearch\CompletionSuggester
	 */
	const COMPLETION = 'completion';

	/**
	 * Profile type used by the phrase suggester (fulltext search only)
	 * @see \CirrusSearch\Query\FullTextQueryStringQueryBuilder::buildSuggestConfig()
	 */
	const PHRASE_SUGGESTER = 'phrase_suggester';

	/**
	 * Profile type used by saneitizer
	 * @see \CirrusSearch\Maintenance\SaneitizeJobs
	 */
	const SANEITIZER = 'saneitizer';

	/**
	 * Profiles used for building fulltext search queries
	 * @see \CirrusSearch\Search\SearchContext::getFulltextQueryBuilderProfile()
	 */
	const FT_QUERY_BUILDER = 'ft_query_builder';

	/**
	 * Profile context used for prefix search queries
	 */
	const CONTEXT_PREFIXSEARCH = 'prefixsearch';

	/**
	 * Default profile context (used by fulltext queries)
	 */
	const CONTEXT_DEFAULT = 'default';

	/**
	 * List of profile repositories, grouped by type and then by repository name.
	 * @var SearchProfileRepository[][]
	 */
	private $repositories = [];

	/**
	 * List of default profile names to use for a given type in a given context
	 * Key path is [type][context]
	 * @var string[][]
	 */
	private $defaultProfiles = [];

	/**
	 * list of overriders, $this->overriders[$type][$context] is an array of SearchProfileOverride
	 * Key path is [type][context]
	 * @var SearchProfileOverride[][][]
	 */
	private $overriders = [];

	/**
	 * @var User
	 */
	private $user;

	/**
	 * @var WebRequest $request
	 */
	private $request;

	/**
	 * @var boolean
	 */
	private $frozen;

	/**
	 * SearchProfileService constructor.
	 * @param WebRequest|null $request obtained from \RequestContext::getMain()->getRequest() if null
	 * @param User|null $user obtained from \RequestContext::getMain()->getUser() if null
	 */
	public function __construct( WebRequest $request = null, User $user = null ) {
		$this->request = $request !== null ? $request : RequestContext::getMain()->getRequest();
		$this->user = $user !== null ? $user : RequestContext::getMain()->getUser();
	}

	/**
	 * @param string $type
	 * @param string $name
	 * @return bool
	 */
	public function hasProfile( $type, $name ) {
		if ( isset( $this->repositories[$type] ) ) {
			foreach ( $this->repositories[$type] as $repo ) {
				if ( $repo->hasProfile( $name ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Load a profile by its name.
	 * It's better to use self::loadProfile and let the service
	 * determine the proper profile to use in a given context.
	 *
	 * @param string $type the type of the profile (see class doc)
	 * @param string $name
	 * @param bool $failIfMissing when true will throw SearchProfileException
	 * @return array|null
	 */
	public function loadProfileByName( $type, $name, $failIfMissing = true ) {
		if ( isset( $this->repositories[$type] ) ) {
			$repos = $this->repositories[$type];
			foreach ( $repos as $repo ) {
				$prof = $repo->getProfile( $name );
				if ( $prof !== null ) {
					return $prof;
				}
			}
		}
		if ( $failIfMissing ) {
			throw new SearchProfileException( "Cannot load a profile type $type: $name not found" );
		}
		return null;
	}

	/**
	 * Load a profile for the context or by its name if name is provided
	 *
	 * @param string $type
	 * @param string $context used to determine the name of the profile if $name is not provided
	 * @param string|null $name force the name of the profile to use
	 * @return array
	 * @see self::getProfileName()
	 */
	public function loadProfile( $type, $context = self::CONTEXT_DEFAULT, $name = null ) {
		if ( $name === null && $context === null ) {
			throw new SearchProfileException( '$name and $context cannot be both null' );
		}
		if ( $name === null ) {
			$name = $this->getProfileName( $type, $context );
		}
		return $this->loadProfileByName( $type, $name );
	}

	/**
	 * @param string $type the type of the profile (see class doc)
	 * @param string $context
	 * @return string
	 */
	public function getProfileName( $type, $context = self::CONTEXT_DEFAULT ) {
		$minPrio = PHP_INT_MAX;
		if ( !isset( $this->defaultProfiles[$type][$context] ) ) {
			throw new SearchProfileException( "No default profile found for $type in context $context" );
		}
		$profile = $this->defaultProfiles[$type][$context];
		if ( !$this->hasProfile( $type, $profile ) ) {
			throw new SearchProfileException( "The default profile $profile does not exist in profile repositories of type $type" );
		}

		if ( !isset( $this->overriders[$type][$context] ) ) {
			return $profile;
		}

		foreach ( $this->overriders[$type][$context] as $overrider ) {
			if ( $overrider->priority() < $minPrio ) {
				$name = $overrider->getOverriddenName();
				if ( $name !== null && $this->hasProfile( $type, $name ) ) {
					$minPrio = $overrider->priority();
					$profile = $name;
				}
			}
		}
		return $profile;
	}

	/**
	 * Register a new profile repository
	 * @param SearchProfileRepository $repository
	 */
	public function registerRepository( SearchProfileRepository $repository ) {
		$this->checkFrozen();
		if ( isset( $this->repositories[$repository->repositoryType()][$repository->repositoryName()] ) ) {
			throw new SearchProfileException( "A profile repository type {$repository->repositoryType()} " .
				"named {$repository->repositoryName()} is already registered." );
		}
		$this->repositories[$repository->repositoryType()][$repository->repositoryName()] = $repository;
	}

	/**
	 * Register a new repository backed by a simple array
	 * @param string $repoType
	 * @param string $repoName
	 * @param array $profiles
	 */
	public function registerArrayRepository( $repoType, $repoName, array $profiles ) {
		$this->registerRepository( ArrayProfileRepository::fromArray( $repoType, $repoName, $profiles ) );
	}

	/**
	 * Register a new repository backed by a PHP file returning an array.
	 *
	 * <b>NOTE:</b> $phpFile is loaded with PHP's require keyword.
	 *
	 * @param string $type
	 * @param string $name
	 * @param string $phpFile
	 * @see FileProfileRepository
	 */
	public function registerFileRepository( $type, $name, $phpFile ) {
		$this->registerRepository( ArrayProfileRepository::fromFile( $type, $name, $phpFile ) );
	}

	/**
	 * List profiles under type $type that are suited
	 * to be exposed to the users.
	 *
	 * This method is provided for convenience and to help
	 * users to discover existing profile.
	 * It's possible that an existing profile may not be listed here
	 * so this method must not be used to verify the existence of a given
	 * profile. Use hasProfile instead.
	 *
	 * @param string $type
	 * @return array
	 */
	public function listExposedProfiles( $type ) {
		$profiles = [];
		if ( isset( $this->repositories[$type] ) ) {
			foreach ( $this->repositories[$type] as $repo ) {
				foreach ( $repo->listExposedProfiles() as $name => $profile ) {
					if ( !isset( $profiles[$name] ) ) {
						$profiles[$name] = $profile;
					}
				}
			}
		}
		return $profiles;
	}

	/**
	 * Register a default profile named $profileName for $type in context $profileContext
	 * It must be an existing profile otherwise it will always fail when trying to determine
	 * the profile name.
	 * @param string $type
	 * @param string $profileContext
	 * @param string $profileName
	 */
	public function registerDefaultProfile( $type, $profileContext, $profileName ) {
		if ( isset( $this->defaultProfiles[$type][$profileContext] ) ) {
			throw new SearchProfileException( "A default profile already exists for $type in context $profileContext" );
		}
		$this->defaultProfiles[$type][$profileContext] = $profileName;
	}

	/**
	 * Register a new profile overrider.
	 * It allows to override the default profile based on the implementation of SearchProfileOverride.
	 * @param string $type
	 * @param string|string[] $profileContext one or multiple contexts
	 * @param SearchProfileOverride $override
	 */
	public function registerProfileOverride( $type, $profileContext, SearchProfileOverride $override ) {
		$this->checkFrozen();
		if ( !is_array( $profileContext ) ) {
			$profileContext = [ $profileContext ];
		}
		foreach ( $profileContext as $context ) {
			$this->overriders[$type][$context][] = $override;
		}
	}

	/**
	 * Register a new overrider using the ConfigSearchProfileOverride implementation
	 * @param string $type
	 * @param string|string[] $profileContext one or multiple contexts
	 * @param Config $config
	 * @param string $configEntry
	 * @see ConfigSearchProfileOverride
	 */
	public function registerConfigOverride( $type, $profileContext, Config $config, $configEntry ) {
		$this->registerProfileOverride( $type, $profileContext, new ConfigSearchProfileOverride( $config, $configEntry ) );
	}

	/**
	 * @param string $type
	 * @param string|string[] $profileContext one or multiple contexts
	 * @param string $uriParam
	 */
	public function registerUriParamOverride( $type, $profileContext, $uriParam ) {
		$this->registerProfileOverride( $type, $profileContext, new UriParamSearchProfileOverride( $this->request, $uriParam ) );
	}

	/**
	 * @param string $type
	 * @param string|string[] $profileContext one or multiple contexts
	 * @param string $userPref the name of the key used to store this user preference
	 */
	public function registerUserPrefOverride( $type, $profileContext, $userPref ) {
		$this->registerProfileOverride( $type, $profileContext, new UserPrefSearchProfileOverride( $this->user, $userPref ) );
	}

	/**
	 * Freeze the service, any attempt to declare a new repository
	 * will fail.
	 */
	public function freeze() {
		$this->frozen = true;
	}

	private function checkFrozen() {
		if ( $this->frozen ) {
			throw new SearchProfileException( self::class . " is frozen, you cannot register new repositories/overriders." );
		}
	}
}
