<?php

namespace CirrusSearch;

use MediaWiki\Interwiki\InterwikiLookup;
use MediaWiki\Registration\ExtensionRegistry;
use Wikimedia\Http\MultiHttpClient;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * Factory class used to create InterwikiResolver
 */
class InterwikiResolverFactory {
	/**
	 * Based on config variables available in $config
	 * returns the approriate the InterwikiResolver
	 * implementation.
	 * Fallback to EmptyInterwikiResolver.
	 *
	 * @param SearchConfig $config
	 * @param WANObjectCache $wanCache Cache object for caching repeated requests
	 * @param InterwikiLookup $iwLookup
	 * @param ExtensionRegistry $extensionRegistry
	 * @param MultiHttpClient $client http client to fetch cirrus config
	 * @return InterwikiResolver
	 * @see CirrusSearchInterwikiResolverFactory::accepts()
	 * @see SiteMatrixInterwikiResolver::accepts()
	 */
	public static function build(
		SearchConfig $config,
		WANObjectCache $wanCache,
		InterwikiLookup $iwLookup,
		ExtensionRegistry $extensionRegistry,
		MultiHttpClient $client
	): InterwikiResolver {
		if ( CirrusConfigInterwikiResolver::accepts( $config ) ) {
			return new CirrusConfigInterwikiResolver( $config, $client, $wanCache, $iwLookup );
		}
		if ( SiteMatrixInterwikiResolver::accepts( $config, $extensionRegistry ) ) {
			return new SiteMatrixInterwikiResolver( $config, $client, $wanCache, $iwLookup );
		}
		return new EmptyInterwikiResolver();
	}
}
