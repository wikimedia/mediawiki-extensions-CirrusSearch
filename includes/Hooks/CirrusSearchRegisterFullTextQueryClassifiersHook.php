<?php

namespace CirrusSearch\Hooks;

use CirrusSearch\Parser\ParsedQueryClassifiersRepository;

/**
 * This is a hook handler interface, see docs/Hooks.md in core.
 * Use the hook name "CirrusSearchRegisterFullTextQueryClassifiers" to register handlers implementing this interface.
 *
 * @stable to implement
 * @ingroup Hooks
 */
interface CirrusSearchRegisterFullTextQueryClassifiersHook {
	/**
	 * This hook is called to register FullText query classifiers
	 *
	 * @param ParsedQueryClassifiersRepository $repository
	 */
	public function onCirrusSearchRegisterFullTextQueryClassifiers( ParsedQueryClassifiersRepository $repository ): void;
}
