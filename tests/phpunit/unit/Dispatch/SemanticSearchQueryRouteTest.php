<?php

namespace CirrusSearch\Dispatch;

use CirrusSearch\CirrusDebugOptions;
use CirrusSearch\CirrusSearch;
use CirrusSearch\CirrusTestCase;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Profile\SearchProfileService;
use CirrusSearch\Search\SearchQuery;
use CirrusSearch\Search\SearchQueryBuilder;

class SemanticSearchQueryRouteTest extends CirrusTestCase {

	/**
	 * @covers \CirrusSearch\Dispatch\SemanticSearchQueryRoute::getProfileContext
	 */
	public function testGetProfileContext() {
		$route = new SemanticSearchQueryRoute( 'foo', [], 1.0 );
		$this->assertSame( SearchProfileService::CONTEXT_SEMANTIC, $route->getProfileContext() );
	}

	/**
	 * @covers \CirrusSearch\Dispatch\SemanticSearchQueryRoute::getSearchEngineEntryPoint
	 */
	public function testGetSearchEngineEntryPoint() {
		$searchEngineEntryPoint = 'a not so random but weird search engine entry point';
		$route = new SemanticSearchQueryRoute( $searchEngineEntryPoint, [], 1.0 );
		$this->assertSame( $searchEngineEntryPoint, $route->getSearchEngineEntryPoint() );
	}

	/**
	 * @covers \CirrusSearch\Dispatch\SemanticSearchQueryRoute::score
	 */
	public function testGetScore() {
		$route = new SemanticSearchQueryRoute( SearchQuery::SEARCH_TEXT, [], 0.4 );
		$this->assertSame( 0.4, $route->score( $this->newQueryBuilder( true )->build() ) );
	}

	/**
	 * @return array
	 */
	public static function provideTestNamespacesRouting() {
		return [
			'simple match' => [
				[ 1 ],
				[ 1 ],
				true
			],
			'simple no match' => [
				[ 1 ],
				[ 0 ],
				false
			],
			'contained match' => [
				[ 0, 1 ],
				[ 1 ],
				true
			],
			'fully equal' => [
				[ 0, 1 ],
				[ 0, 1 ],
				true
			],
			'one unsupported' => [
				[ 0, 1 ],
				[ 0, 1, 2 ],
				false
			],
			'all accepted' => [
				[],
				[ 0, 1, 2 ],
				true
			],
			'all provided' => [
				[ 0 ],
				[],
				false
			],
		];
	}

	/**
	 * @covers \CirrusSearch\Dispatch\SemanticSearchQueryRoute::score
	 * @dataProvider provideTestNamespacesRouting
	 */
	public function testNamespacesRouting( $acceptedNs, $queryNs, $acceptRoute ) {
		$route = new SemanticSearchQueryRoute( SearchQuery::SEARCH_TEXT, $acceptedNs, 1.0 );
		$query = $this->newQueryBuilder( true )
			->setInitialNamespaces( $queryNs )
			->build();
		$expectedScore = $acceptRoute ? 1.0 : SearchQueryRoute::REJECT_ROUTE;
		$this->assertSame( $expectedScore, $route->score( $query ) );
	}

	/**
	 * The option is read from the query rather than from the ambient web
	 * request, so a caller that passed its own CirrusDebugOptions still gets
	 * routed.
	 *
	 * @covers \CirrusSearch\Dispatch\SemanticSearchQueryRoute::score
	 */
	public function testSemanticSearchOptionComesFromTheQuery() {
		$route = new SemanticSearchQueryRoute( SearchQuery::SEARCH_TEXT, [], 1.0 );

		$this->assertSame( 1.0, $route->score( $this->newQueryBuilder( true )->build() ) );
		$this->assertSame( SearchQueryRoute::REJECT_ROUTE,
			$route->score( $this->newQueryBuilder( false )->build() ) );
	}

	/**
	 * @return array
	 */
	public static function provideTestForcedProfilesRouting() {
		return [
			'no forced profiles' => [
				[],
				true
			],
			'single autoselect profile' => [
				[ SearchProfileService::FT_QUERY_BUILDER => CirrusSearch::AUTOSELECT_PROFILE ],
				true
			],
			'single non-autoselect profile' => [
				[ SearchProfileService::FT_QUERY_BUILDER => 'custom-profile' ],
				false
			],
			'multiple profiles all autoselect' => [
				[
					SearchProfileService::FT_QUERY_BUILDER => CirrusSearch::AUTOSELECT_PROFILE,
					SearchProfileService::RESCORE => CirrusSearch::AUTOSELECT_PROFILE,
				],
				true
			],
			'multiple profiles one non-autoselect' => [
				[
					SearchProfileService::FT_QUERY_BUILDER => CirrusSearch::AUTOSELECT_PROFILE,
					SearchProfileService::RESCORE => 'custom-rescore',
				],
				false
			],
			'multiple profiles all non-autoselect' => [
				[
					SearchProfileService::FT_QUERY_BUILDER => 'custom-completion',
					SearchProfileService::RESCORE => 'custom-rescore',
				],
				false
			],
		];
	}

	/**
	 * @covers \CirrusSearch\Dispatch\SemanticSearchQueryRoute::score
	 * @dataProvider provideTestForcedProfilesRouting
	 */
	public function testForcedProfilesRouting( $forcedProfiles, $acceptRoute ) {
		$route = new SemanticSearchQueryRoute( SearchQuery::SEARCH_TEXT, [], 1.0 );
		$queryBuilder = $this->newQueryBuilder( true );
		foreach ( $forcedProfiles as $type => $profile ) {
			$queryBuilder->addForcedProfile( $type, $profile );
		}
		$expectedScore = $acceptRoute ? 1.0 : SearchQueryRoute::REJECT_ROUTE;
		$this->assertSame( $expectedScore, $route->score( $queryBuilder->build() ) );
	}

	private function newQueryBuilder( bool $semantic ): SearchQueryBuilder {
		$debugOptions = $semantic
			? CirrusDebugOptions::forSemanticSearchUnitTests()
			: CirrusDebugOptions::defaultOptions();
		return $this->getNewFTSearchQueryBuilder( new HashSearchConfig( [] ), 'foo' )
			->setDebugOptions( $debugOptions );
	}
}
