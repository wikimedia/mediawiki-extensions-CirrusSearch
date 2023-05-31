<?php

namespace CirrusSearch\Hooks;

use CirrusSearch\Maintenance\AnalysisConfigBuilder;

/**
 * This is a hook handler interface, see docs/Hooks.md in core.
 * Use the hook name "CirrusSearchAnalysisConfig" to register handlers implementing this interface.
 *
 * @stable to implement
 * @ingroup Hooks
 */
interface CirrusSearchAnalysisConfigHook {
	/**
	 * This hook is called to alter the analysis configuration
	 *
	 * @param array &$config
	 * @param AnalysisConfigBuilder $analyisConfigBuilder
	 */
	public function onCirrusSearchAnalysisConfig( array &$config, AnalysisConfigBuilder $analyisConfigBuilder ): void;
}
