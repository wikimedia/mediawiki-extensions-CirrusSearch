<?php

namespace CirrusSearch\Extra\MultiList;

/**
 * Item of a `super_detect_noop`-handled `multilist`.
 *
 * @see https://gerrit.wikimedia.org/r/plugins/gitiles/search/extra/+/refs/heads/master/docs/super_detect_noop.md
 */
class MultiListItem {

	public const DELIMITER = '/';

	private string $prefix;

	private string $name;

	/**
	 * @param string $prefix Prefix
	 * @param string $name Name
	 */
	public function __construct( string $prefix, string $name ) {
		$this->prefix = $prefix;
		$this->name = $name;
	}

	public function __toString() {
		return $this->prefix . self::DELIMITER . $this->name;
	}

	public function getPrefix(): string {
		return $this->prefix;
	}

	public function getName(): string {
		return $this->name;
	}

}
