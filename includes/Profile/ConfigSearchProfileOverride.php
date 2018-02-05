<?php


namespace CirrusSearch\Profile;

/**
 * Overrider that gets its name using an entry in a Config object
 */
class ConfigSearchProfileOverride implements SearchProfileOverride {

	/**
	 * @var \Config
	 */
	private $config;

	/**
	 * @var string
	 */
	private $configEntry;

	/**
	 * @var int
	 */
	private $priority;

	/**
	 * ConfigSearchProfileOverride constructor.
	 * @param \Config $config
	 * @param string $configEntry the name of the config entry holding the name of the overridden profile
	 * @param int $priority
	 */
	public function __construct( \Config $config, $configEntry, $priority = SearchProfileOverride::CONFIG_PRIO ) {
		$this->config = $config;
		$this->configEntry = $configEntry;
		$this->priority = $priority;
	}

	/**
	 * Get the overridden name or null if it cannot be overridden.
	 * @return string|null
	 */
	public function getOverriddenName() {
		if ( $this->config->has( $this->configEntry ) ) {
			return $this->config->get( $this->configEntry );
		}
		return null;
	}

	/**
	 * The priority of this override, lower wins
	 * @return int
	 */
	public function priority() {
		return $this->priority;
	}
}
