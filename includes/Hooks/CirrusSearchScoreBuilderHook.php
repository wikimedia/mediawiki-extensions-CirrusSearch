<?php

namespace CirrusSearch\Hooks;

use CirrusSearch\Search\Rescore\BoostFunctionBuilder;
use CirrusSearch\Search\SearchContext;

/**
 * This is a hook handler interface, see docs/Hooks.md in core.
 * Use the hook name "CirrusSearchScoreBuilder" to register handlers implementing this interface.
 *
 * @stable to implement
 * @ingroup Hooks
 */
interface CirrusSearchScoreBuilderHook {
	/**
	 * @param array $definition
	 * @param SearchContext $context
	 * @param BoostFunctionBuilder|null &$builder
	 */
	public function onCirrusSearchScoreBuilder( array $definition, SearchContext $context, ?BoostFunctionBuilder &$builder ): void;
}
