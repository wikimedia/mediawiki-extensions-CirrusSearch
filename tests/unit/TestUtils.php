<?php

namespace CirrusSearch\Test;

use MediaWiki\MediaWikiServices;

class HashSearchConfig extends \CirrusSearch\SearchConfig {
	public function __construct( array $settings ) {
		$this->setSource( new \HashConfig( $settings ) );
	}
}

class DummyConnection extends \CirrusSearch\Connection {
	public function __construct() {
		$this->config = MediaWikiServices::getInstance()
			->getConfigFactory()
			->makeConfig( 'CirrusSearch' );
	}
	
	public function getServerList() {
		return array( 'localhost' );
	}
}
