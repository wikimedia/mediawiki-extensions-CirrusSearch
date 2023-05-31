<?php

namespace CirrusSearch\Hooks;

/**
 * This is a hook handler interface, see docs/Hooks.md in core.
 * Use the hook name "CirrusSearchSimilarityConfig" to register handlers implementing this interface.
 *
 * @stable to implement
 * @ingroup Hooks
 */
interface CirrusSearchSimilarityConfigHook {
	/**
	 * This hook is called register new similarity configurations
	 *
	 * @param array &$similarityConfig
	 */
	public function onCirrusSearchSimilarityConfig( array &$similarityConfig ): void;
}
