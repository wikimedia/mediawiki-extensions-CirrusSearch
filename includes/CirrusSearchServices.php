<?php

namespace CirrusSearch;

use MediaWiki\MediaWikiServices;
use MediaWiki\Sparql\SparqlClient;

/**
 * A simple wrapper for MediaWikiServices, to support type safety when accessing
 * services defined by this extension.
 */
class CirrusSearchServices {

	/** @var MediaWikiServices */
	private $services;

	public static function wrap( MediaWikiServices $services ): self {
		return new self( $services );
	}

	public function __construct( MediaWikiServices $services ) {
		$this->services = $services;
	}

	public function getCirrusSearch(): CirrusSearch {
		return $this->services->get( 'CirrusSearch' );
	}

	public function getCirrusCategoriesClient(): SparqlClient {
		return $this->services->get( 'CirrusCategoriesClient' );
	}

}
