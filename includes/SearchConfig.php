<?php

namespace CirrusSearch;

use RequestContext;
use SiteConfiguration;

/**
 * Configuration class encapsulating Searcher environment.
 * This config class can import settings from the environment globals,
 * or from specific wiki configuration.
 */
class SearchConfig implements \Config {
	/**
	 * Override settings
	 * @var array
	 */
	private $source;

	/**
	 * Wiki variables prefix.
	 * @var string
	 */
	private $prefix = '';

	/**
	 * Create new search config
	 * @param string $overrideWiki Non-default wiki name for override settings
	 * @param string $overrideSuffix The suffix of the non-default wiki
	 */
	public function __construct( $overrideWiki = null, $overrideSuffix = null ) {
		if ( $overrideWiki ) {
			$this->wikiId = $overrideSuffix ? "$overrideWiki-$overrideSuffix" : $overrideWiki;
			if ( $this->wikiId != wfWikiID() ) {
				$this->source = new \HashConfig( $this->getConfigVars( $overrideWiki, 'wgCirrus' ) );
				$this->prefix = 'wg';
				// Re-create language object
				$this->source->set( 'wgContLang', \Language::factory( $this->source->get( 'wgLanguageCode' ) ) );
				return;
			}
		}
		$this->source = new \GlobalVarConfig();
		$this->wikiId = wfWikiId();
	}

	/**
	 * Get search config vars from other wiki's config
	 * @param string $wiki Target wiki
	 * @param string $prefix Cirrus variables prefix
	 * @return array
	 */
	private function getConfigVars( $wiki, $prefix ) {
		global $wgConf;

		$cirrusVars = array_filter( array_keys($GLOBALS),
				function($key) use($prefix) {
					if ( !isset( $GLOBALS[$key] ) || is_object( $GLOBALS[$key] ) ) {
						return false;
					}
					return strncmp( $key, $prefix, strlen($prefix) ) === 0;
				}
		);
		$cirrusVars[] = 'wgLanguageCode';
		return $wgConf->getConfig( $wiki, $cirrusVars );
	}

	public function has($name) {
		return $this->source->has( $this->prefix . $name );
	}

	public function get($name) {
		if ( !$this->source->has( $this->prefix . $name ) ) {
			return null;
		}
		return $this->source->get( $this->prefix . $name );
	}

	/**
	 * Produce new configuration from globals
	 * @return \CirrusSearch\SearchConfig
	 */
	public static function newFromGlobals() {
		return new self();
	}

	/**
	 * Return configured Wiki ID
	 * @return string
	 */
	public function getWikiId() {
		return $this->wikiId;
	}

	/**
	 * Get user's language
	 * @return string User's language code
	 */
	public function getUserLanguage() {
		// I suppose using $wgLang would've been more evil than this, but
		// only marginally so. Find some real context to use here.
		return RequestContext::getMain()->getLanguage()->getCode();
	}

	/**
	 * Get chain of elements from config array
	 * @param string $configName
	 * @param string ... list of path elements
	 * @return mixed Returns value or null if not present
	 */
	public function getElement($configName) {
		if( !$this->has( $configName ) ) {
			return null;
		}
		$data = $this->get( $configName );
		$path = func_get_args();
		array_shift( $path );
		foreach( $path as $el ) {
			if( !isset( $data[$el] ) ) {
				return null;
			}
			$data = $data[$el];
		}
		return $data;
	}
}