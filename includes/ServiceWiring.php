<?php
/**
 * Services for CirrusSearch extensions
 */

use CirrusSearch\CirrusSearch;
use CirrusSearch\Query\DeepcatFeature;
use MediaWiki\MediaWikiServices;
use MediaWiki\Sparql\SparqlClient;

return [
	'CirrusSearch' => static function ( MediaWikiServices $services ): CirrusSearch {
		return new CirrusSearch();
	},

	// SPARQL client for deep category search
	'CirrusCategoriesClient' => static function ( MediaWikiServices $services ) {
		$config = $services->getMainConfig();
		$client = new SparqlClient( $config->get( 'CirrusSearchCategoryEndpoint' ),
			$services->getHttpRequestFactory() );
		$client->setTimeout( DeepcatFeature::TIMEOUT );
		$client->setClientOptions( [
			'userAgent' => DeepcatFeature::USER_AGENT,
		] );
		return $client;
	},
];
