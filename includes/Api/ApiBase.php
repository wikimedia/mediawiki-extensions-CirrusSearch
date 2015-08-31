<?php

namespace CirrusSearch\Api;

use ApiBase as CoreApiBase;
use CirrusSearch\Connection;
use ConfigFactory;

abstract class ApiBase extends CoreApiBase {
	private $connection;

	public function getCirrusConnection() {
		if ($this->connection === null) {
			$config = ConfigFactory::getDefaultInstance()->makeConfig( 'CirrusSearch' );
			$this->connection = new Connection( $config );
		}
		return $this->connection;
	}
}
