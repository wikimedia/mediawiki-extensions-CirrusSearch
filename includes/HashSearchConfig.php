<?php

namespace CirrusSearch;

use CirrusSearch\Profile\SearchProfileServiceFactoryFactory;
use InvalidArgumentException;
use MediaWiki\Config\Config;
use MediaWiki\Config\GlobalVarConfig;
use MediaWiki\Config\HashConfig;
use MediaWiki\Config\MultiConfig;

/**
 * SearchConfig implemenation backed by a simple HashConfig
 */
class HashSearchConfig extends SearchConfig {
	public const FLAG_INHERIT = 'inherit';

	/** @var bool */
	private $localWiki = false;

	/**
	 * @param array $settings config vars
	 * @param string[] $flags customization flags:
	 * - inherit: config vars not part the settings provided are fetched from GlobalVarConfig
	 * @param Config|null $inherited (only useful when the inherit flag is set)
	 * @param SearchProfileServiceFactoryFactory|null $searchProfileServiceFactoryFactory
	 */
	public function __construct(
		array $settings,
		array $flags = [],
		?Config $inherited = null,
		?SearchProfileServiceFactoryFactory $searchProfileServiceFactoryFactory = null
	) {
		parent::__construct( $searchProfileServiceFactoryFactory );
		$config = new HashConfig( $settings );
		$extra = array_diff( $flags, [ self::FLAG_INHERIT ] );
		if ( $extra ) {
			throw new InvalidArgumentException( "Unknown config flags: " . implode( ',', $extra ) );
		}

		if ( in_array( self::FLAG_INHERIT, $flags ) ) {
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
