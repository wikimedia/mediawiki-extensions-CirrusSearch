<?php

namespace CirrusSearch\Profile;

use CirrusSearch\SearchConfig;

/**
 * Repository dedicated to completion queries
 * This one cannot simply use existing implementation because
 * we need to filter the profiles based on the availability of
 * some fields in the index.
 */
class CompletionSearchProfileRepository implements SearchProfileRepository {

	/**
	 * @var SearchProfileRepository
	 */
	private $wrapped;

	/**
	 * @param string $repoType
	 * @param string $repoName
	 * @param string $phpFile
	 * @param SearchConfig $config
	 * @return self
	 */
	public static function fromFile( $repoType, $repoName, $phpFile, SearchConfig $config ): self {
		// TODO: find a construct that does not require duplicating ArrayProfileRepository::fromFile
		return new self( $repoType, $repoName, $config, static function () use ( $phpFile ) {
			return require $phpFile;
		} );
	}

	/**
	 * @param string $repoType
	 * @param string $repoName
	 * @param string $configEntry
	 * @param SearchConfig $config
	 * @return self
	 */
	public static function fromConfig( $repoType, $repoName, $configEntry, SearchConfig $config ): self {
		return new self( $repoType, $repoName, $config, static function () use ( $configEntry, $config ) {
			return ConfigProfileRepository::extractConfig( $configEntry, $config );
		} );
	}

	/**
	 * @param SearchProfileRepository $repository
	 * @param SearchConfig $config
	 * @return self
	 */
	public static function fromRepo( SearchProfileRepository $repository, SearchConfig $config ): self {
		return new self( $repository->repositoryType(), $repository->repositoryName(), $config, static function () use ( $repository ) {
			return $repository->listExposedProfiles();
		} );
	}

	/**
	 * @param string $repoType
	 * @param string $repoName
	 * @param SearchConfig $config
	 * @param callable $arrayLoader callable that resolves to an array of original profiles
	 */
	private function __construct( $repoType, $repoName, SearchConfig $config, callable $arrayLoader ) {
		$this->wrapped = ArrayProfileRepository::lazyLoaded( $repoType, $repoName, static function () use ( $arrayLoader, $config ) {
			$profiles = [];

			$allowedFields = [ 'suggest' => true, 'suggest-stop' => true ];
			// Check that we can use the subphrases FST
			if ( $config->getElement( 'CirrusSearchCompletionSuggesterSubphrases', 'use' ) ) {
				$allowedFields['suggest-subphrases'] = true;
			}
			$originalProfiles = $arrayLoader();
			if ( !is_array( $originalProfiles ) ) {
				throw new SearchProfileException( "Expected an array but got a " . get_debug_type( $originalProfiles ) );
			}
			foreach ( $originalProfiles as $name => $settings ) {
				$allowed = true;
				if ( !isset( $settings['fst'] ) ) {
					throw new SearchProfileException( "Completion profile $name must have a fst key defined" );
				}
				foreach ( $settings['fst'] as $value ) {
					if ( empty( $allowedFields[$value['field']] ) ) {
						$allowed = false;
						break;
					}
				}
				if ( !$allowed ) {
					continue;
				}
				$profiles[$name] = $settings;
			}
			return $profiles;
		} );
	}

	/**
	 * @return string
	 */
	public function repositoryType() {
		return $this->wrapped->repositoryType();
	}

	/**
	 * @return string
	 */
	public function repositoryName() {
		return $this->wrapped->repositoryName();
	}

	/**
	 * @param string $name
	 * @return array
	 */
	public function getProfile( $name ) {
		return $this->wrapped->getProfile( $name );
	}

	/**
	 * @param string $name
	 * @return bool
	 */
	public function hasProfile( $name ) {
		return $this->wrapped->hasProfile( $name );
	}

	/**
	 * @return array[]
	 */
	public function listExposedProfiles() {
		return $this->wrapped->listExposedProfiles();
	}
}
