<?php

namespace CirrusSearch\Test;

use MediaWiki\MediaWikiServices;

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
