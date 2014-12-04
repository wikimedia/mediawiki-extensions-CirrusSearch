<?php

namespace CirrusSearch\Maintenance\Validators;

use CirrusSearch\Maintenance\Maintenance;
use Elastica\Client;

class IndexAllAliasValidator extends IndexAliasValidator {
	/**
	 * @var string prefix of names of indices that should be removed
	 */
	protected $shouldRemovePrefix;

	public function __construct( Client $client, $aliasName, $specificIndexName, $startOver, $type, Maintenance $out = null ) {
		parent::__construct( $client, $aliasName, $specificIndexName, $startOver, $out );
		$this->shouldRemovePrefix = $type;
	}

	protected function shouldRemoveFromAlias( $name ) {
		// Only if the name starts with the type being processed otherwise we'd
		// remove the content index from the all alias.
		return strpos( $name, "$this->shouldRemovePrefix" ) === 0;
	}
}
