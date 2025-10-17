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
	 * @return SecondTryRunner
	 */
	public function create( string $context ): SecondTryRunner {
		$profile = $this->config->getProfileService()->loadProfile( SearchProfileService::SECOND_TRY, $context );
		$methods = [];
		$weights = [];
		foreach ( $profile['strategies'] as $name => $config ) {
			$weight = $config['weight'] ?? $config;
			$settings = is_array( $config ) ? $config : [];
			if ( !is_float( $weight ) ) {
				throw new SearchProfileException( "Invalid search strategy $name settings in second-try profile" );
			}
			$methods[$name] = $this->secondTrySearchFactory->build( $name, $settings );
			$weights[$name] = $weight;
		}
		return new SecondTryRunner( $methods, $weights );
	}
}
