<?php

namespace CirrusSearch\SecondTry;

use CirrusSearch\Profile\SearchProfileException;
use CirrusSearch\Profile\SearchProfileService;
use CirrusSearch\SearchConfig;

class SecondTryRunnerFactory {
	private SecondTrySearchFactory $secondTrySearchFactory;
	private SearchConfig $config;

	public function __construct( SecondTrySearchFactory $secondTrySearchFactory, SearchConfig $config ) {
		$this->secondTrySearchFactory = $secondTrySearchFactory;
		$this->config = $config;
	}

	/**
	 * Create the SecondTryRunner configured against the given profile name.
	 *
	 * @param string $context SearchProfileService context for which to load the second-try runner
	 * @param string|null $contextProfile
	 * @return SecondTryRunner
	 */
	public function create( string $context, ?string $contextProfile = null ): SecondTryRunner {
		return self::buildFromProfile(
			$this->config->getProfileService()->loadProfile( SearchProfileService::SECOND_TRY, $context ),
			$context,
			$contextProfile
		);
	}

	/**
	 * Create the SecondTryRunner configured against the given profile data.
	 *
	 * @param array $profile the profile to use
	 * @param string $context context for which we use this second try profile
	 * @param string|null $contextProfile profile in which the second try config will be used
	 * @return SecondTryRunner
	 */
	public function buildFromProfile( array $profile, string $context, ?string $contextProfile = null ): SecondTryRunner {
		$methods = [];
		$weights = [];
		foreach ( $profile['strategies'] as $name => $config ) {
			$weight = $config['weight'] ?? $config;
			$settings = $config['settings'] ?? [];
			$allowedProfiles = $config['allowed_profiles'][$context] ?? [];
			if ( $allowedProfiles !== [] && $contextProfile !== null && !in_array( $contextProfile, $allowedProfiles ) ) {
				// Skip this strategy if it's not fit for this profile
				continue;
			}
			if ( !is_float( $weight ) ) {
				throw new SearchProfileException( "Invalid search strategy $name settings in second-try profile" );
			}
			$methods[$name] = $this->secondTrySearchFactory->build( $name, $settings );
			$weights[$name] = $weight;
		}
		return new SecondTryRunner( $methods, $weights );
	}
}
