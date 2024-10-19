<?php
/**
 * Services for CirrusSearch extensions
 */

use CirrusSearch\CirrusSearch;
use CirrusSearch\CirrusSearchHookRunner;
use CirrusSearch\Connection;
use CirrusSearch\EventBusWeightedTagSerializer;
use CirrusSearch\EventBusWeightedTagsUpdater;
use CirrusSearch\InterwikiResolver;
use CirrusSearch\InterwikiResolverFactory;
use CirrusSearch\Profile\SearchProfileServiceFactory;
use CirrusSearch\Query\DeepcatFeature;
use CirrusSearch\SearchConfig;
use CirrusSearch\Updater;
use CirrusSearch\WeightedTagsUpdater;
use MediaWiki\Extension\EventBus\Serializers\EventSerializer;
use MediaWiki\Extension\EventBus\Serializers\MediaWiki\PageEntitySerializer;
use MediaWiki\Http\Telemetry;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;
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
	'CirrusCategoriesClient' => static function ( MediaWikiServices $services ): SparqlClient {
		$config = $services->getMainConfig();
		$client = new SparqlClient( $config->get( 'CirrusSearchCategoryEndpoint' ),
			$services->getHttpRequestFactory() );
		$client->setTimeout( DeepcatFeature::TIMEOUT );
		$client->setClientOptions( [
			'userAgent' => DeepcatFeature::USER_AGENT,
		] );
		return $client;
	},
	InterwikiResolver::SERVICE => static function ( MediaWikiServices $services ): InterwikiResolver {
		$config = $services->getConfigFactory()
			->makeConfig( 'CirrusSearch' );
		$client = $services->getHttpRequestFactory()->createMultiClient( [
			'connTimeout' => $config->get( 'CirrusSearchInterwikiHTTPConnectTimeout' ),
			'reqTimeout' => $config->get( 'CirrusSearchInterwikiHTTPTimeout' )
		] );
		return InterwikiResolverFactory::build(
		/** @phan-suppress-next-line PhanTypeMismatchArgumentSuperType $config is actually a SearchConfig */
			$config,
			$services->getMainWANObjectCache(),
			$services->getInterwikiLookup(),
			$services->getExtensionRegistry(),
			$client
		);
	},
	SearchProfileServiceFactory::SERVICE_NAME => static function ( MediaWikiServices $services ): SearchProfileServiceFactory {
		$config = $services->getConfigFactory()
			->makeConfig( 'CirrusSearch' );
		return new SearchProfileServiceFactory( $services->getService( InterwikiResolver::SERVICE ),
		/** @phan-suppress-next-line PhanTypeMismatchArgumentSuperType $config is actually a SearchConfig */
			$config,
			$services->getLocalServerObjectCache(),
			new CirrusSearchHookRunner( $services->getHookContainer() ),
			$services->getUserOptionsLookup(),
			ExtensionRegistry::getInstance()
		);
	},
	WeightedTagsUpdater::SERVICE => static function ( MediaWikiServices $services ): WeightedTagsUpdater {
		/**
		 * @var SearchConfig $searchConfig
		 */
		$searchConfig = $services->getConfigFactory()->makeConfig( 'CirrusSearch' );

		if ( $searchConfig->get( 'CirrusSearchEnableEventBusWeightedTags' ) ) {
			$eventBusFactory = $services->getService( 'EventBus.EventBusFactory' );

			$mainConfig = $services->getMainConfig();
			$globalIdGenerator = $services->getGlobalIdGenerator();

			$eventSerializer = new EventSerializer( $mainConfig, $globalIdGenerator, Telemetry::getInstance() );
			$pageEntitySerializer = new PageEntitySerializer( $mainConfig, $services->getTitleFormatter() );
			$weightedTagSerializer = new EventBusWeightedTagSerializer( $eventSerializer, $pageEntitySerializer );
			$wikiPageFactory = $services->getWikiPageFactory();

			return new EventBusWeightedTagsUpdater( $eventBusFactory, $weightedTagSerializer, $wikiPageFactory );
		}

		/** @phan-suppress-next-line PhanTypeMismatchArgumentSuperType $config is actually a SearchConfig */
		return new Updater( new Connection( $searchConfig ) );
	}
];

// @codeCoverageIgnoreEnd
