<?php

namespace CirrusSearch\Test;

use CirrusSearch\SearchConfig;

/**
 * Keeps track of all requested configuration keys
 */
class SearchConfigUsageDecorator extends SearchConfig {
	/** @var array<string,true> */
	private static array $usedConfigKeys = [];

	/** @inheritDoc */
	public function get( $name ) {
		$val = parent::get( $name );
		// Some config vars are objects
		if ( !is_object( $val ) ) {
			static::$usedConfigKeys[$name] = true;
		}
		return $val;
	}

	public static function getUsedConfigKeys(): array {
		return array_keys( static::$usedConfigKeys );
	}

	public static function resetUsedConfigKeys() {
		static::$usedConfigKeys = [];
	}
}
