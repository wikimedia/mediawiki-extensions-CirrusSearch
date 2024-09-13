<?php

namespace CirrusSearch\Elastica;

class DeprecationLoggedHttps extends DeprecationLoggedHttp {
	/** @inheritDoc */
	protected $_scheme = 'https'; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore
}
