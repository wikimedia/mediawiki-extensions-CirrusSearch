<?php

namespace CirrusSearch\Hooks;

use CirrusSearch\Query\SimpleKeywordFeature;
use CirrusSearch\SearchConfig;

/**
 * This is a hook handler interface, see docs/Hooks.md in core.
 * Use the hook name "CirrusSearchAddQueryFeatures" to register handlers implementing this interface.
 *
 * @stable to implement
 * @ingroup Hooks
 */
interface CirrusSearchAddQueryFeaturesHook {
	/**
	 * This hook is called to register new search keywords
	 * @param SearchConfig $config
	 * @param SimpleKeywordFeature[] &$extraFeatures
	 */
	public function onCirrusSearchAddQueryFeatures( SearchConfig $config, array &$extraFeatures ): void;
}
