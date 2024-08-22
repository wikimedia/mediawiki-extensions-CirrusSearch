<?php

namespace CirrusSearch\Test;

use CirrusSearch\SearchConfig;

/**
 * Keeps track of all requested configuration keys
 */
class SearchConfigUsageDecorator extends SearchConfig {
	/** @var true[] */
	private static $usedConfigKeys = [];

	public function get( $name ) {
		$val = parent::get( $name );
		// Some config vars are objects
		if ( !is_object( $val ) ) {
			static::$usedConfigKeys[$name] = true;
		}
		return $val;
	}

	public static function getUsedConfigKeys() {
		return static::$usedConfigKeys;
	}

	public static function resetUsedConfigKeys() {
		static::$usedConfigKeys = [];
	}
}
