<?php

namespace CirrusSearch\Elastica;

class DeprecationLoggedHttps extends DeprecationLoggedHttp {
	// @phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore
	protected $_scheme = 'https';
}
