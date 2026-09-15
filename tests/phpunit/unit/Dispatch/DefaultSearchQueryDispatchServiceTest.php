<?php

namespace CirrusSearch\Dispatch;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\Dispatch\Voter\AllQueriesCandidateVoter;
use CirrusSearch\Dispatch\Voter\NamespaceVetoVoter;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Profile\SearchProfileException;
use CirrusSearch\Profile\SearchProfileService;
use CirrusSearch\Search\SearchQuery;
use Wikimedia\Assert\ParameterAssertionException;

/**
 * @covers \CirrusSearch\Dispatch\DefaultSearchQueryDispatchService
 * @covers \CirrusSearch\Dispatch\RouteDecision
 */
class DefaultSearchQueryDispatchServiceTest extends CirrusTestCase {

	/**
	 * A route accepting every query in $namespaces, as the routes registered through
	 * registerFTSearchQueryRoute() are built.
	 */
	private function route( string $context, float $score, array $namespaces = [] ): VotedSearchQueryRoute {
		$voters = [];
		if ( $namespaces !== [] ) {
			$voters['namespaces'] = new NamespaceVetoVoter( $namespaces );
		}
		$voters['all'] = new AllQueriesCandidateVoter();
		return new VotedSearchQueryRoute( $context, SearchQuery::SEARCH_TEXT, $context, $score, $voters );
	}

	private function cirrusDefault(): DefaultSearchQueryRoute {
		return new DefaultSearchQueryRoute( 'cirrus_default', SearchQuery::SEARCH_TEXT,
			SearchProfileService::CONTEXT_DEFAULT );
	}

	/**
	 * @param SearchQueryRoute[] $routes routes that bid
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

	private function query( array $namespaces = [], ?string $forcedRescoreProfile = null ): SearchQuery {
		$builder = $this->getNewFTSearchQueryBuilder( new HashSearchConfig( [] ), 'foo' );
		if ( $namespaces !== [] ) {
			$builder->setInitialNamespaces( $namespaces );
		}
		if ( $forcedRescoreProfile !== null ) {
			$builder->addForcedProfile( SearchProfileService::RESCORE, $forcedRescoreProfile );
		}
		return $builder->build();
	}

	/**
	 * A request that named a profile made the choice dispatch is there to make, so no election
	 * is held. The route below would have won one. Its voters are the ones
	 * registerFTSearchQueryRoute() builds, so an extension route is covered by the same rule.
	 */
	public function testForcedProfileTakesTheDefaultRoute() {
		$default = $this->cirrusDefault();
		$service = $this->service( [ $this->route( 'semantic', 1.0, [ 0 ] ) ], $default );

		$this->assertSame( 'semantic',
			$service->bestRoute( $this->query( [ 0 ] ) )->getProfileContext() );
		$this->assertSame( $default, $service->bestRoute( $this->query( [ 0 ], 'classic' ) ) );
	}

	/**
	 * A query no route accepted goes to the default route, whether the routes turned it down
	 * or there were none to ask.
	 */
	public function testNothingAcceptedGoesToTheDefault() {
		$default = $this->cirrusDefault();

		$rejecting = $this->service( [ $this->route( 'unrelated', 0.5, [ 1 ] ) ], $default );
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
			$this->route( 'bestFor0', 0.3, [ 0 ] ),
			$this->route( 'weakestFor0', 0.2, [ 0 ] ),
			$this->route( 'unrelated', 0.5, [ 1 ] ),
			$this->route( 'tooLate', 0.3, [ 0 ] ),
		] );
		$this->assertEquals( 'bestFor0',
			$service->bestRoute( $this->query( [ 0 ] ) )->getProfileContext() );
	}

	public function testMax() {
		$service = $this->service( [
			$this->route( 'firstFor0', 0.3, [ 0 ] ),
			$this->route( 'weakestFor0', 0.2, [ 0 ] ),
			$this->route( 'unrelated', 1.0, [ 1 ] ),
			$this->route( 'bestFor0', 1.0, [ 0 ] ),
		] );
		$this->assertEquals( 'bestFor0',
			$service->bestRoute( $this->query( [ 0 ] ) )->getProfileContext() );
	}

	public function testAmbiguousMax() {
		$service = $this->service( [
			$this->route( 'firstFor0', 1.0, [ 0 ] ),
			$this->route( 'weakestFor0', 0.2, [ 0 ] ),
			$this->route( 'unrelated', 1.0, [ 1 ] ),
			$this->route( 'bestFor0', 1.0, [ 0 ] ),
		] );
		try {
			$service->bestRoute( $this->query( [ 0 ] ) );
			$this->fail( "Invalid configuration must produce a SearchProfileException" );
		} catch ( SearchProfileException $e ) {
			$this->assertStringContainsString( 'firstFor0', $e->getMessage() );
			$this->assertStringContainsString( 'bestFor0', $e->getMessage() );
		}
	}

	public static function provideContradictoryDecisions() {
		return [
			'accepted without a bid' => [ true, RouteDecision::REJECT_ROUTE ],
			'rejected with a bid' => [ false, 0.5 ],
			'bid above the max' => [ true, 1.5 ],
		];
	}

	/**
	 * The election drops the routes that did not accept and ranks the rest on their bid, so a
	 * decision that disagrees with its own score would go missing without a word.
	 *
	 * @dataProvider provideContradictoryDecisions
	 */
	public function testDecisionMustAgreeWithItsScore( bool $accepted, float $score ) {
		$this->expectException( ParameterAssertionException::class );
		new RouteDecision( 'ctx', $accepted, RouteDecision::REASON_CANDIDATE, $score );
	}
}
