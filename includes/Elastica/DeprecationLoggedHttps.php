<?php

namespace CirrusSearch\Elastica;

class DeprecationLoggedHttps extends DeprecationLoggedHttp {
	// phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore, MediaWiki.Commenting.PropertyDocumentation
	protected $_scheme = 'https';
}
