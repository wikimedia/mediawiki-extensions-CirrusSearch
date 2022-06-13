<?php

namespace CirrusSearch\Profile;

use Config;
use ExtensionRegistry;

/**
 * Profile repository backed by a Config object.
 */
class ConfigProfileRepository implements SearchProfileRepository {

	/**
	 * @var string
	 */
	private $type;

	/**
	 * @var string
	 */
	private $name;

	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @var string
	 */
	private $configEntry;

	/**
	 * @param string $type
	 * @param string $name
	 * @param string $configEntry the name of config key holding the list of profiles
	 * @param Config $config
	 */
	public function __construct( $type, $name, $configEntry, Config $config ) {
		$this->type = $type;
		$this->name = $name;
		$this->configEntry = $configEntry;
		$this->config = $config;
	}

	/**
	 * @return string
	 */
	public function repositoryType() {
		return $this->type;
	}

	/**
	 * The repository name
	 * @return string
	 */
	public function repositoryName() {
		return $this->name;
	}

	/**
	 * Load a profile named $name
	 * @param string $name
	 * @return array[]|null the profile data or null if not found
	 * @throws SearchProfileException
	 */
	public function getProfile( $name ) {
		$profiles = $this->extractProfiles();
		return $profiles[$name] ?? null;
	}

	/**
	 * Check if a profile named $name exists in this repository
	 * @param string $name
	 * @return bool
	 */
	public function hasProfile( $name ) {
		return isset( $this->extractProfiles()[$name] );
	}

	/**
	 * Get the list of profiles that we want to expose to the user.
	 *
	 * @return array[] list of profiles index by name
	 */
	public function listExposedProfiles() {
		return $this->extractProfiles();
	}

	private function extractProfiles(): array {
		$configEntry = $this->configEntry;
		$config = $this->config;
		return self::extractConfig( $configEntry, $config ) + self::extractAttribute( $configEntry );
	}

	/**
	 * @param string $configEntry
	 * @param Config $config
	 * @return array
	 * @internal For use by CompletionSearchProfileRepository only.
	 */
	public static function extractConfig( string $configEntry, Config $config ): array {
		if ( !$config->has( $configEntry ) ) {
			return [];
		}
		$profiles = $config->get( $configEntry );
		if ( !is_array( $profiles ) ) {
			throw new SearchProfileException( "Config entry {$configEntry} must be an array or unset" );
		}
		return $profiles;
	}

	/**
	 * @param string $configEntry
	 * @return array
	 * @internal For use by CompletionSearchProfileRepository only.
	 */
	public static function extractAttribute( string $configEntry ): array {
		$profiles = ExtensionRegistry::getInstance()->getAttribute( $configEntry );
		if ( !is_array( $profiles ) ) {
			throw new SearchProfileException( "Attribute {configEntry} must be an array or unset" );
		}
		return $profiles;
	}
}
