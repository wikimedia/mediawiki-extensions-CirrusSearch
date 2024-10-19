<?php

namespace CirrusSearch\Profile;

use MediaWiki\Registration\ExtensionRegistry;

class ExtensionRegistryProfileRepository implements SearchProfileRepository {
	private string $type;
	private string $name;
	private string $attributeName;
	private ExtensionRegistry $extensionRegistry;

	/**
	 * @param string $type
	 * @param string $name
	 * @param string $attributeName
	 * @param ExtensionRegistry $extensionRegistry
	 */
	public function __construct( string $type, string $name, string $attributeName, ExtensionRegistry $extensionRegistry ) {
		$this->type = $type;
		$this->name = $name;
		$this->attributeName = $attributeName;
		$this->extensionRegistry = $extensionRegistry;
	}

	public function repositoryType() {
		return $this->type;
	}

	public function repositoryName() {
		return $this->name;
	}

	public function getProfile( $name ) {
		$profiles = $this->extractAttribute();
		return $profiles[$name] ?? null;
	}

	public function extractAttribute(): array {
		$profiles = $this->extensionRegistry->getAttribute( $this->attributeName );
		if ( !is_array( $profiles ) ) {
			throw new SearchProfileException( "Attribute {configEntry} must be an array or unset" );
		}
		return $profiles;
	}

	/**
	 * @inheritDoc
	 */
	public function hasProfile( $name ) {
		return isset( $this->extractAttribute()[$name] );
	}

	/**
	 * @inheritDoc
	 */
	public function listExposedProfiles() {
		return $this->extractAttribute();
	}
}
