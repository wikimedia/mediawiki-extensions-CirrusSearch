<?php

namespace CirrusSearch\Profile;

/**
 * Override the default profile.
 */
interface SearchProfileOverride {
	/**
	 * Default priority for uri param overrides
	 */
	const URI_PARAM_PRIO = 100;

	/**
	 * Default priority for user pref overrides
	 */
	const USER_PREF_PRIO = 200;

	/**
	 * Default priority for config overrides
	 */
	const CONFIG_PRIO = 300;

	/**
	 * Get the overridden name or null if it cannot be overridden.
	 * @return string|null
	 */
	public function getOverriddenName();

	/**
	 * The priority of this override, lower wins
	 * @return int
	 */
	public function priority();
}
