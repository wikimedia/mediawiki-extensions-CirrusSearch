<?php
namespace CirrusSearch\Test;

class HashSearchConfig extends \CirrusSearch\SearchConfig {
	public function __construct( array $settings ) {
		$this->setSource( new \HashConfig( $settings ) );
	}
}

class DummyConnection extends \CirrusSearch\Connection {
	public function __construct() {
	}
}
