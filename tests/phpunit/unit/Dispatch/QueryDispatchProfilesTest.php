<?php

namespace CirrusSearch\Dispatch;

use CirrusSearch\CirrusConfigNames;
use CirrusSearch\CirrusTestCase;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Profile\SearchProfileService;
use CirrusSearch\Search\SearchQuery;

/**
 * The shipped route tables have to keep saying what settings.txt says they say.
 *
 * @covers \CirrusSearch\Dispatch\VotedSearchQueryRoute
 * @group CirrusSearch
 */
class QueryDispatchProfilesTest extends CirrusTestCase {

	private function profiles(): array {
		return require __DIR__ . '/../../../../profiles/QueryDispatchProfiles.config.php';
	}

	/**
	 * A wiki that can do semantic retrieval, so the semantic route is built.
	 */
	private function config(): HashSearchConfig {
		return new HashSearchConfig( [
			CirrusConfigNames::DefaultSemanticProfile => 'semantic',
			CirrusConfigNames::SemanticQueryLengthThreshold => 4,
		] );
	}

	/**
	 * The routes that bid for a query. The default route is not one of them, so it is left
	 * out here as the dispatch service leaves it out of the election.
	 *
	 * @return VotedSearchQueryRoute[] keyed by route name
	 */
	private function routes( string $profileName ): array {
		$config = $this->config();
		$profile = $this->profiles()[$profileName];
		$routes = [];
		foreach ( $profile['routes'] as $name => $entry ) {
			if ( $name === $profile['default_route'] ) {
				continue;
			}
			$routes[$name] = VotedSearchQueryRoute::fromProfileEntry( $config,
				SearchQuery::SEARCH_TEXT, $name, $entry );
		}
		return $routes;
	}

	public static function provideVoterOrder() {
		return [
			'default routes nothing on its own' => [
				'default', [ 'namespaces', 'query_classes', 'debug_option' ],
			],
			'query length proposes the route' => [
				'semantic_by_query_length',
				[ 'namespaces', 'query_classes', 'debug_option', 'query_length' ],
			],
			'the title match runs last, after the cheap voters' => [
				'semantic_by_query_length_unless_title_match',
				[ 'namespaces', 'query_classes', 'debug_option', 'query_length', 'title_match' ],
			],
		];
	}

	/**
	 * Order is behaviour here: a veto or a force ends the pass, so a voter that talks to the
	 * search backend must not run before the ones that can settle it for free.
	 *
	 * @dataProvider provideVoterOrder
	 */
	public function testSemanticVoterOrder( string $profileName, array $expected ) {
		$this->assertSame( $expected, $this->routes( $profileName )['semantic']->getVoterNames() );
	}

	/**
	 * A query no route accepted still has somewhere to go, and naming where is the profile's
	 * job rather than something the dispatch service can guess.
	 *
	 * @dataProvider provideVoterOrder
	 */
	public function testEveryProfileNamesADefaultRouteItDeclares( string $profileName ) {
		$profile = $this->profiles()[$profileName];
		$name = $profile['default_route'];
		$this->assertArrayHasKey( $name, $profile['routes'] );

		$route = DefaultSearchQueryRoute::fromProfileEntry( SearchQuery::SEARCH_TEXT, $name,
			$profile['routes'][$name] );
		$this->assertSame( SearchProfileService::CONTEXT_DEFAULT, $route->getProfileContext() );
	}

	/**
	 * The semantic route bids the max, so nothing else may bid it too.
	 *
	 * @dataProvider provideVoterOrder
	 */
	public function testOnlyOneRouteClaimsTheMaxScore( string $profileName ) {
		$maxed = [];
		foreach ( $this->routes( $profileName ) as $name => $route ) {
			$entry = $this->profiles()[$profileName]['routes'][$name];
			if ( (float)$entry['score'] === 1.0 ) {
				$maxed[] = $name;
			}
		}
		$this->assertSame( [ 'semantic' ], $maxed );
	}

	public static function provideAdvancedSyntax() {
		return [
			'a keyword' => [ 'insource:catapult how do catapults work' ],
			'a negation' => [ 'how do catapults work -trebuchet' ],
			'a wildcard' => [ 'how do catapult* work' ],
		];
	}

	/**
	 * The semantic builder embeds the query text and drops the parse, so a query that means
	 * more than its words stays lexical however many tokens it has.
	 *
	 * @dataProvider provideAdvancedSyntax
	 */
	public function testAdvancedSyntaxIsKeptOffTheSemanticRoute( string $term ) {
		$query = $this->getNewFTSearchQueryBuilder( $this->config(), $term )
			->setInitialNamespaces( [ NS_MAIN ] )
			->build();

		$decision = $this->routes( 'semantic_by_query_length' )['semantic']->decide( $query );
		$this->assertFalse( $decision->isAccepted() );
		$this->assertSame( RouteDecision::REASON_VETOED, $decision->getReason() );
	}
}
