<?php

namespace CirrusSearch\Hooks;

use CirrusSearch\Profile\SearchProfileService;

/**
 * This is a hook handler interface, see docs/Hooks.md in core.
 * Use the hook name "CirrusSearchProfileService" to register handlers implementing this interface.
 *
 * @stable to implement
 * @ingroup Hooks
 */
interface CirrusSearchProfileServiceHook {
	/**
	 * This hook is called to register search profiles
	 *
	 * @param SearchProfileService $service
	 */
	public function onCirrusSearchProfileService( SearchProfileService $service ): void;
}
