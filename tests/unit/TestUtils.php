<?php

namespace CirrusSearch\Test;

use GlobalVarConfig;
use MediaWiki\MediaWikiServices;
use MultiConfig;

class HashSearchConfig extends \CirrusSearch\SearchConfig {
	public function __construct( array $settings, array $flags = [] ) {
		$config = new \HashConfig( $settings );
		if ( in_array( 'inherit', $flags ) ) {
			$config = new MultiConfig( [ $config, new GlobalVarConfig ] );
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
}

class DummyConnection extends \CirrusSearch\Connection {
	public function __construct() {
		$this->config = MediaWikiServices::getInstance()
			->getConfigFactory()
			->makeConfig( 'CirrusSearch' );
	}

	public function getServerList() {
		return [ 'localhost' ];
	}
}
