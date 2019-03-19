<?php

namespace CirrusSearch\Elastica;

/**
 * Exception thrown while trying to fetch a connection from the
 * hhvm curl connection pool.
 */
class PooledHttpConnectionException extends \Exception {

	/**
	 * @param string $errorMessage
	 */
	public function __construct( $errorMessage ) {
		parent::__construct( $errorMessage );
	}
}
