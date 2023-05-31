<?php

namespace CirrusSearch\Hooks;

use CirrusSearch\Maintenance\MappingConfigBuilder;

/**
 * This is a hook handler interface, see docs/Hooks.md in core.
 * Use the hook name "CirrusSearchMappingConfig" to register handlers implementing this interface.
 *
 * @stable to implement
 * @ingroup Hooks
 */
interface CirrusSearchMappingConfigHook {
	/**
	 * This hook is called to alter the mapping configuration
	 *
	 * @param array &$mappingConfig
	 * @param MappingConfigBuilder $mappingConfigBuilder
	 */
	public function onCirrusSearchMappingConfig( array &$mappingConfig, MappingConfigBuilder $mappingConfigBuilder ): void;
}
