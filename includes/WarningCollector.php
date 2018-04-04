<?php

namespace CirrusSearch;

/**
 * Warning collector interface
 */
interface WarningCollector {

	/**
	 * Add a warning
	 *
	 * NOTE: $param1 $param2 and $param3 are just poor-man variadic args
	 *
	 * TODO: switch to variadic args once php 5.5 support is dropped
	 * @param string $message i18n message key
	 * @param string|null $param1
	 * @param string|null $param2
	 * @param string|null $param3
	 */
	function addWarning( $message, $param1 = null, $param2 = null, $param3 = null );
}
