<?php

namespace CirrusSearch\Elastica;

/**
 * Connection pooling for https
 */
class PooledHttps extends PooledHttp {
	/**
	 * Https scheme.
	 *
	 * @var string https scheme
	 */
	protected $_scheme = 'https';
}
