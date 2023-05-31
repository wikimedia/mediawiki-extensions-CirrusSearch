<?php

namespace CirrusSearch;

use CirrusSearch\Maintenance\AnalysisConfigBuilder;
use CirrusSearch\Maintenance\MappingConfigBuilder;
use CirrusSearch\Search\Rescore\BoostFunctionBuilder;
use MediaWiki\HookContainer\HookContainer;

/**
 * @internal
 */
class CirrusSearchHookRunner implements
	\CirrusSearch\Hooks\CirrusSearchRegisterFullTextQueryClassifiersHook,
	\CirrusSearch\Hooks\CirrusSearchAddQueryFeaturesHook,
	\CirrusSearch\Hooks\CirrusSearchSimilarityConfigHook,
	\CirrusSearch\Hooks\CirrusSearchAnalysisConfigHook,
	\CirrusSearch\Hooks\CirrusSearchMappingConfigHook,
	\CirrusSearch\Hooks\CirrusSearchProfileServiceHook,
	\CirrusSearch\Hooks\CirrusSearchScoreBuilderHook
{
	private HookContainer $hookContainer;

	public function __construct( HookContainer $hookContainer ) {
		$this->hookContainer = $hookContainer;
	}

	public function onCirrusSearchRegisterFullTextQueryClassifiers( Parser\ParsedQueryClassifiersRepository $repository ): void {
		$this->hookContainer->run( 'CirrusSearchRegisterFullTextQueryClassifiers', [ $repository ] );
	}

	public function onCirrusSearchAddQueryFeatures( SearchConfig $config, array &$extraFeatures ): void {
		$this->hookContainer->run( 'CirrusSearchAddQueryFeatures', [ $config, &$extraFeatures ] );
	}

	public function onCirrusSearchSimilarityConfig( array &$similarityConfig ): void {
		$this->hookContainer->run( 'CirrusSearchSimilarityConfig', [ &$similarityConfig ] );
	}

	public function onCirrusSearchAnalysisConfig( array &$config, AnalysisConfigBuilder $analyisConfigBuilder ): void {
		$this->hookContainer->run( 'CirrusSearchAnalysisConfig', [ &$config, $analyisConfigBuilder ] );
	}

	public function onCirrusSearchMappingConfig( array &$mappingConfig, MappingConfigBuilder $mappingConfigBuilder ): void {
		$this->hookContainer->run( 'CirrusSearchMappingConfig', [ &$mappingConfig, $mappingConfigBuilder ] );
	}

	public function onCirrusSearchProfileService( Profile\SearchProfileService $service ): void {
		$this->hookContainer->run( 'CirrusSearchProfileService', [ $service ] );
	}

	public function onCirrusSearchScoreBuilder( array $definition, Search\SearchContext $context, ?BoostFunctionBuilder &$builder ): void {
		$this->hookContainer->run( 'CirrusSearchScoreBuilder', [ $definition, $context, &$builder ] );
	}
}
