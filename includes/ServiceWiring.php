<?php
/**
 * Services for CirrusSearch extensions
 */

use CirrusSearch\CirrusSearch;
use CirrusSearch\CirrusSearchHookRunner;
use CirrusSearch\InterwikiResolver;
use CirrusSearch\InterwikiResolverFactory;
use CirrusSearch\Profile\SearchProfileServiceFactory;
use CirrusSearch\Query\DeepcatFeature;
use MediaWiki\MediaWikiServices;
use MediaWiki\Sparql\SparqlClient;

// PHP unit does not understand code coverage for this file
// as the @covers annotation cannot cover a specific file
// This is fully tested in ServiceWiringTest.php
// @codeCoverageIgnoreStart

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
	InterwikiResolverFactory::SERVICE => static function ( MediaWikiServices $services ) {
		return InterwikiResolverFactory::newFactory();
	},
	InterwikiResolver::SERVICE => static function ( MediaWikiServices $services ) {
		$config = $services->getConfigFactory()
			->makeConfig( 'CirrusSearch' );
		return $services
			->getService( InterwikiResolverFactory::SERVICE )
			->getResolver( $config );
	},
	SearchProfileServiceFactory::SERVICE_NAME => static function ( MediaWikiServices $services ) {
		$config = $services->getConfigFactory()
			->makeConfig( 'CirrusSearch' );
		return new SearchProfileServiceFactory(
			$services->getService( InterwikiResolver::SERVICE ),
			/** @phan-suppress-next-line PhanTypeMismatchArgumentSuperType $config is actually a SearchConfig */
			$config,
			$services->getLocalServerObjectCache(),
			new CirrusSearchHookRunner( $services->getHookContainer() ),
			$services->getUserOptionsLookup()
		);
	}
];

// @codeCoverageIgnoreEnd
