<?php

namespace CirrusSearch\Dispatch;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Profile\SearchProfileException;
use CirrusSearch\Profile\SearchProfileService;
use CirrusSearch\Search\SearchQuery;

/**
 * @covers \CirrusSearch\Dispatch\DefaultSearchQueryDispatchService
 */
class DefaultSearchQueryDispatchServiceTest extends CirrusTestCase {

	private function cirrusDefault(): DefaultSearchQueryRoute {
		return new DefaultSearchQueryRoute( SearchQuery::SEARCH_TEXT,
			SearchProfileService::CONTEXT_DEFAULT );
	}

	/**
	 * @param SearchQueryRoute[] $routes routes that compete for a query
	 * @param DefaultSearchQueryRoute|null $default
	 */
	private function service(
		array $routes,
		?DefaultSearchQueryRoute $default = null
	): DefaultSearchQueryDispatchService {
		return new DefaultSearchQueryDispatchService(
			[ SearchQuery::SEARCH_TEXT => $routes ],
			[ SearchQuery::SEARCH_TEXT => $default ?? $this->cirrusDefault() ] );
	}

	private function query( array $namespaces = [] ): SearchQuery {
		$builder = $this->getNewFTSearchQueryBuilder( new HashSearchConfig( [] ), 'foo' );
		if ( $namespaces !== [] ) {
			$builder->setInitialNamespaces( $namespaces );
		}
		return $builder->build();
	}

	/**
	 * A query no route wanted goes to the default route, whether the routes turned it down
	 * or there were none to ask.
	 */
	public function testNothingWantedGoesToTheDefault() {
		$default = $this->cirrusDefault();

		$rejecting = $this->service(
			[ new BasicSearchQueryRoute( SearchQuery::SEARCH_TEXT, [ 1 ], [], 'unrelated', 0.5 ) ],
			$default );
		$this->assertSame( $default, $rejecting->bestRoute( $this->query( [ 0 ] ) ) );

		$empty = $this->service( [], $default );
		$this->assertSame( $default, $empty->bestRoute( $this->query( [ 0 ] ) ) );
	}

	/**
	 * What a caller gets when it will not dispatch at all.
	 */
	public function testDefaultProfileContext() {
		$this->assertSame( SearchProfileService::CONTEXT_DEFAULT,
			$this->service( [] )->defaultProfileContext( SearchQuery::SEARCH_TEXT ) );
	}

	public function testBestWithOrdering() {
		$service = $this->service( [
			new BasicSearchQueryRoute( SearchQuery::SEARCH_TEXT, [ 0 ], [], 'bestFor0', 0.3 ),
			new BasicSearchQueryRoute( SearchQuery::SEARCH_TEXT, [ 0 ], [], 'weakestFor0', 0.2 ),
			new BasicSearchQueryRoute( SearchQuery::SEARCH_TEXT, [ 1 ], [], 'unrelated', 0.5 ),
			new BasicSearchQueryRoute( SearchQuery::SEARCH_TEXT, [ 0 ], [], 'tooLate', 0.3 ),
		] );
		$this->assertEquals( 'bestFor0',
			$service->bestRoute( $this->query( [ 0 ] ) )->getProfileContext() );
	}

	public function testMax() {
		$service = $this->service( [
			new BasicSearchQueryRoute( SearchQuery::SEARCH_TEXT, [ 0 ], [], 'firstFor0', 0.3 ),
			new BasicSearchQueryRoute( SearchQuery::SEARCH_TEXT, [ 0 ], [], 'weakestFor0', 0.2 ),
			new BasicSearchQueryRoute( SearchQuery::SEARCH_TEXT, [ 1 ], [], 'unrelated', 1.0 ),
			new BasicSearchQueryRoute( SearchQuery::SEARCH_TEXT, [ 0 ], [], 'bestFor0', 1.0 ),
		] );
		$this->assertEquals( 'bestFor0',
			$service->bestRoute( $this->query( [ 0 ] ) )->getProfileContext() );
	}

	public function testAmbiguousMax() {
		$service = $this->service( [
			new BasicSearchQueryRoute( SearchQuery::SEARCH_TEXT, [ 0 ], [], 'firstFor0', 1.0 ),
			new BasicSearchQueryRoute( SearchQuery::SEARCH_TEXT, [ 0 ], [], 'weakestFor0', 0.2 ),
			new BasicSearchQueryRoute( SearchQuery::SEARCH_TEXT, [ 1 ], [], 'unrelated', 1.0 ),
			new BasicSearchQueryRoute( SearchQuery::SEARCH_TEXT, [ 0 ], [], 'bestFor0', 1.0 ),
		] );
		try {
			$service->bestRoute( $this->query( [ 0 ] ) );
			$this->fail( "Invalid configuration must produce a SearchProfileException" );
		} catch ( SearchProfileException $e ) {
			$this->assertStringContainsString( 'firstFor0', $e->getMessage() );
			$this->assertStringContainsString( 'bestFor0', $e->getMessage() );
		}
	}
}
