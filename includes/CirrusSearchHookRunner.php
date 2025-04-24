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
		$this->hookContainer->run( 'CirrusSearchRegisterFullTextQueryClassifiers',
			[ $repository ],
			[ 'abortable' => false ]
		);
	}

	public function onCirrusSearchAddQueryFeatures( SearchConfig $config, array &$extraFeatures ): void {
		$this->hookContainer->run( 'CirrusSearchAddQueryFeatures',
			[ $config, &$extraFeatures ],
			[ 'abortable' => false ]
		);
	}

	public function onCirrusSearchSimilarityConfig( array &$similarityConfig ): void {
		$this->hookContainer->run( 'CirrusSearchSimilarityConfig',
			[ &$similarityConfig ],
			[ 'abortable' => false ]
		);
	}

	public function onCirrusSearchAnalysisConfig( array &$config, AnalysisConfigBuilder $analyisConfigBuilder ): void {
		$this->hookContainer->run( 'CirrusSearchAnalysisConfig',
			[ &$config, $analyisConfigBuilder ],
			[ 'abortable' => false ]
		);
	}

	public function onCirrusSearchMappingConfig( array &$mappingConfig, MappingConfigBuilder $mappingConfigBuilder ): void {
		$this->hookContainer->run( 'CirrusSearchMappingConfig',
			[ &$mappingConfig, $mappingConfigBuilder ],
			[ 'abortable' => false ]
		);
	}

	public function onCirrusSearchProfileService( Profile\SearchProfileService $service ): void {
		$this->hookContainer->run( 'CirrusSearchProfileService',
			[ $service ],
			[ 'abortable' => false ]
		);
	}

	public function onCirrusSearchScoreBuilder( array $definition, Search\SearchContext $context, ?BoostFunctionBuilder &$builder ): void {
		$this->hookContainer->run( 'CirrusSearchScoreBuilder',
			[ $definition, $context, &$builder ],
			// abortable because the type of function we need is in the $definition array and the
			// first extension that's able to build it and assign the builder var should win.
			[ 'abortable' => true ]
		 );
	}
}
