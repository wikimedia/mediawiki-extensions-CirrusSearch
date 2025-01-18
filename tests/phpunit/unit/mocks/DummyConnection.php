<?php

namespace CirrusSearch\Test;

use CirrusSearch\SearchConfig;
use MediaWiki\MediaWikiServices;

class DummyConnection extends \CirrusSearch\Connection {
	public function __construct( ?SearchConfig $config = null ) {
		$this->config = $config ?? MediaWikiServices::getInstance()
			->getConfigFactory()
			->makeConfig( 'CirrusSearch' );
	}

	/** @inheritDoc */
	public function getServerList() {
		return [ 'localhost' ];
	}

	/** @inheritDoc */
	public function getClusterName() {
		return 'default';
	}
}
