<?php

namespace CirrusSearch\Dispatch;

use CirrusSearch\CirrusDebugOptions;
use CirrusSearch\CirrusSearch;
use CirrusSearch\CirrusTestCase;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Profile\SearchProfileService;
use CirrusSearch\Search\SearchQuery;

class SemanticSearchQueryRouteTest extends CirrusTestCase {

	/**
	 * @covers \CirrusSearch\Dispatch\SemanticSearchQueryRoute::getProfileContext
	 */
	public function testGetProfileContext() {
		$debug = CirrusDebugOptions::defaultOptions();
		$route = new SemanticSearchQueryRoute( 'foo', $debug, [], 1.0 );
		$this->assertSame( SearchProfileService::CONTEXT_SEMANTIC, $route->getProfileContext() );
	}

	/**
	 * @covers \CirrusSearch\Dispatch\SemanticSearchQueryRoute::getSearchEngineEntryPoint
	 */
	public function testGetSearchEngineEntryPoint() {
		$searchEngineEntryPoint = 'a not so random but weird search engine entry point';
		$debug = CirrusDebugOptions::defaultOptions();
		$route = new SemanticSearchQueryRoute( $searchEngineEntryPoint, $debug, [], 1.0 );
		$this->assertSame( $searchEngineEntryPoint, $route->getSearchEngineEntryPoint() );
	}

	/**
	 * @covers \CirrusSearch\Dispatch\SemanticSearchQueryRoute::score
	 */
	public function testGetScore() {
		$debug = CirrusDebugOptions::forSemanticSearchUnitTests();
		$route = new SemanticSearchQueryRoute( SearchQuery::SEARCH_TEXT, $debug, [], 0.4 );
		$query = $this->getNewFTSearchQueryBuilder( new HashSearchConfig( [] ), 'foo' )
			->build();
		$this->assertSame( 0.4, $route->score( $query ) );
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
		$debug = CirrusDebugOptions::forSemanticSearchUnitTests();
		$route = new SemanticSearchQueryRoute( SearchQuery::SEARCH_TEXT, $debug, $acceptedNs, 1.0 );
		$query = $this->getNewFTSearchQueryBuilder( new HashSearchConfig( [] ), 'foo' )
			->setInitialNamespaces( $queryNs )
			->build();
		$expectedScore = $acceptRoute ? 1.0 : SearchQueryRoute::REJECT_ROUTE;
		$this->assertSame( $expectedScore, $route->score( $query ) );
	}

	/**
	 * @covers \CirrusSearch\Dispatch\SemanticSearchQueryRoute::score
	 */
	public function testSemanticSearchOption() {
		$query = $this->getNewFTSearchQueryBuilder( new HashSearchConfig( [] ), 'test' )
			->build();

		// Route with semantic search enabled should return score
		$enabledRoute = new SemanticSearchQueryRoute(
			SearchQuery::SEARCH_TEXT,
			CirrusDebugOptions::forSemanticSearchUnitTests(),
			[],
			1.0
		);
		$this->assertSame( 1.0, $enabledRoute->score( $query ) );

		// Route with default options (semantic search disabled) should return 0
		$disabledRoute = new SemanticSearchQueryRoute(
			SearchQuery::SEARCH_TEXT,
			CirrusDebugOptions::defaultOptions(),
			[],
			1.0
		);
		$this->assertSame( SearchQueryRoute::REJECT_ROUTE, $disabledRoute->score( $query ) );
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
		$debug = CirrusDebugOptions::forSemanticSearchUnitTests();
		$route = new SemanticSearchQueryRoute( SearchQuery::SEARCH_TEXT, $debug, [], 1.0 );
		$queryBuilder = $this->getNewFTSearchQueryBuilder( new HashSearchConfig( [] ), 'foo' );
		foreach ( $forcedProfiles as $type => $profile ) {
			$queryBuilder->addForcedProfile( $type, $profile );
		}
		$query = $queryBuilder->build();
		$expectedScore = $acceptRoute ? 1.0 : SearchQueryRoute::REJECT_ROUTE;
		$this->assertSame( $expectedScore, $route->score( $query ) );
	}
}
