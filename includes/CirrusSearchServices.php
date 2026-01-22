<?php

namespace CirrusSearch;

use MediaWiki\MediaWikiServices;

/**
 * A simple wrapper for MediaWikiServices, to support type safety when accessing
 * services defined by this extension.
 */
class CirrusSearchServices {

	/** @var MediaWikiServices */
	private MediaWikiServices $services;

	public static function wrap( MediaWikiServices $services ): self {
		return new self( $services );
	}

	public function __construct( MediaWikiServices $services ) {
		$this->services = $services;
	}

	public function getCirrusSearch(): CirrusSearch {
		return $this->services->get( 'CirrusSearch' );
	}

	public function getCirrusCategoriesClient(): CachedSparqlClient {
		return $this->services->get( 'CirrusCategoriesClient' );
	}

	public function getWeightedTagsUpdater(): WeightedTagsUpdater {
		return $this->services->get( WeightedTagsUpdater::SERVICE );
	}

}
