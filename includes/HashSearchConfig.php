<?php

namespace CirrusSearch;

use GlobalVarConfig;
use MultiConfig;

/**
 * SearchConfig implemenation backed by a simple \HashConfig
 */
class HashSearchConfig extends SearchConfig {
	/** @var bool */
	private $localWiki = false;

	/**
	 * @param array $settings config vars
	 * @param string[] $flags customization flags:
	 * - inherit: config vars not part the settings provided are fetched from GlobalVarConfig
	 * - load-cont-lang: eagerly load ContLang from \Language::factory( 'LanguageCode' )
	 * @param \Config|null $inherited (only useful when the inherit flag is set)
	 */
	public function __construct( array $settings, array $flags = [], \Config $inherited = null ) {
		parent::__construct();
		$config = new \HashConfig( $settings );
		if ( in_array( 'load-cont-lang', $flags ) && !$config->has( 'ContLang' ) && $config->has( 'LanguageCode' ) ) {
			$config->set( 'ContLang', \Language::factory( $config->get( 'LanguageCode' ) ) );
		}

		if ( in_array( 'inherit', $flags ) ) {
			$config = new MultiConfig( [ $config, $inherited ?? new GlobalVarConfig ] );
			$this->localWiki = !isset( $settings['_wikiID' ] );
		}
		$this->setSource( $config );
	}

	/**
	 * Allow overriding Wiki ID
	 * @return mixed|string
	 */
	public function getWikiId() {
		if ( $this->has( '_wikiID' ) ) {
			return $this->get( '_wikiID' );
		}
		return parent::getWikiId();
	}

	public function getHostWikiConfig(): SearchConfig {
		if ( $this->localWiki ) {
			return $this;
		}
		return parent::getHostWikiConfig();
	}

	public function isLocalWiki() {
		return $this->localWiki;
	}
}
