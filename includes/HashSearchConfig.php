<?php

namespace CirrusSearch;

use GlobalVarConfig;
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
