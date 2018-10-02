<?php

namespace CirrusSearch\Profile;

class StaticProfileOverride implements SearchProfileOverride {

	/**
	 * @var string
	 */
	private $name;

	/**
	 * @var int
	 */
	private $priority;

	/**
	 * StaticProfileOverride constructor.
	 * @param string $name
	 * @param int $priority
	 */
	public function __construct( $name, $priority ) {
		$this->name = $name;
		$this->priority = $priority;
	}

	/**
	 * Get the overridden name or null if it cannot be overridden.
	 * @return string|null
	 */
	public function getOverriddenName() {
		return $this->name;
	}

	/**
	 * The priority of this override, lower wins
	 * @return int
	 */
	public function priority() {
		return $this->priority;
	}
}
