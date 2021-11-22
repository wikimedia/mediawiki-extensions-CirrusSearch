<?php

namespace CirrusSearch\Profile;

use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserOptionsLookup;

/**
 * Overrider based on user preference.
 */
class UserPrefSearchProfileOverride implements SearchProfileOverride {
	/**
	 * @var UserIdentity
	 */
	private $user;

	/**
	 * @var UserOptionsLookup
	 */
	private $userOptionsLookup;

	/**
	 * @var string name of the preference
	 */
	private $preference;

	/**
	 * @var int
	 */
	private $priority;

	/**
	 * @param UserIdentity $user
	 * @param UserOptionsLookup $userOptionsLookup
	 * @param string $preference
	 * @param int $priority
	 */
	public function __construct(
		UserIdentity $user,
		UserOptionsLookup $userOptionsLookup,
		$preference,
		$priority = SearchProfileOverride::USER_PREF_PRIO
	) {
		$this->user = $user;
		$this->userOptionsLookup = $userOptionsLookup;
		$this->preference = $preference;
		$this->priority = $priority;
	}

	/**
	 * Get the overridden name or null if it cannot be overridden.
	 * @param string[] $contextParams
	 * @return string|null
	 */
	public function getOverriddenName( array $contextParams ) {
		// Only check user options if the user is logged to avoid loading
		// default user options.
		if ( $this->user->getId() === 0 ) {
			return null;
		}
		return $this->userOptionsLookup->getOption( $this->user, $this->preference );
	}

	/**
	 * The priority of this override, lower wins
	 * @return int
	 */
	public function priority() {
		return $this->priority;
	}

	/**
	 * @return array
	 */
	public function explain(): array {
		return [
			'type' => 'userPreference',
			'priority' => $this->priority(),
			'userPreference' => $this->preference
		];
	}
}
