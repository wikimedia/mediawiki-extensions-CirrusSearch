<?php

namespace CirrusSearch;

use MediaWiki\Sparql\SparqlClient;
use Wikimedia\ObjectCache\WANObjectCache;

class CachedSparqlClient {
	private SparqlClient $client;
	private WANObjectCache $cache;
	private int $ttl;
	private string $cacheGroup;

	public function __construct( SparqlClient $client, WANObjectCache $cache, int $ttl, string $cacheGroup ) {
		$this->client = $client;
		$this->cache = $cache;
		$this->ttl = $ttl;
		$this->cacheGroup = $cacheGroup;
	}

	public function query( string $sparql, bool $rawData = false ): array {
		return $this->cache->getWithSetCallback(
			$this->cache->makeKey( 'cirrussearch', 'sparql', $this->cacheGroup, md5( $sparql ), (int)$rawData ),
			$this->ttl,
			function () use ( $sparql, $rawData ) {
				// Throws on failure, which won't be cached.
				return $this->client->query( $sparql, $rawData );
			}
		);
	}
}
