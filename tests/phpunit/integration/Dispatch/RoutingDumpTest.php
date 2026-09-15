<?php

namespace CirrusSearch\Dispatch;

use CirrusSearch\CirrusConfigNames;
use CirrusSearch\CirrusDebugOptions;
use CirrusSearch\CirrusIntegrationTestCase;
use CirrusSearch\CirrusSearch;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Profile\SearchProfileService;
use CirrusSearch\Query\SemanticSearchQueryBuilder;
use CirrusSearch\Searcher;

/**
 * The routing decision has to survive the whole way from the profile service, through the
 * dispatch service and the search context, to the &cirrusDumpQuery output.
 *
 * @covers \CirrusSearch\Searcher
 * @covers \CirrusSearch\Search\SearchContext::fromSearchQuery
 * @group CirrusSearch
 * @group Database
 */
class RoutingDumpTest extends CirrusIntegrationTestCase {

	private function dumpQuery( array $config, string $queryString,
		?CirrusDebugOptions $debugOptions = null
	): array {
		$engine = new CirrusSearch(
			new HashSearchConfig( $config, [ HashSearchConfig::FLAG_INHERIT ] ),
			$debugOptions ?? CirrusDebugOptions::forDumpingQueriesInUnitTests()
		);
		return $engine->searchText( $queryString )->getValue();
	}

	public function testDecisionReachesTheQueryDump() {
		$dump = $this->dumpQuery( [
			CirrusConfigNames::DefaultSemanticProfile => 'semantic',
			CirrusConfigNames::QueryDispatchProfile => 'semantic_by_query_length',
			CirrusConfigNames::SemanticQueryLengthThreshold => 4,
		], 'catapult' );

		$this->assertSame( [
			'winner' => 'cirrus_default',
			'context' => SearchProfileService::CONTEXT_DEFAULT,
			'profile' => 'semantic_by_query_length',
			'routes' => [
				'semantic' => [
					'accepted' => false,
					'reason' => RouteDecision::REASON_NO_CANDIDATE,
					'context' => SearchProfileService::CONTEXT_SEMANTIC,
					'score' => 0.0,
					'votes' => [
						'namespaces' => 'abstain',
						'query_classes' => 'abstain',
						'debug_option' => 'abstain',
						'query_length' => 'abstain',
					],
				],
				// Asked last, and only because nothing else took the query.
				'cirrus_default' => [
					'accepted' => true,
					'reason' => RouteDecision::REASON_DEFAULT,
					'context' => SearchProfileService::CONTEXT_DEFAULT,
					'score' => 1.0,
					'votes' => [],
				],
			],
		], $dump[Searcher::ROUTING_DUMP_KEY] );
	}

	/**
	 * A wiki with only the default route had no choice to make, and the dump says so rather
	 * than leaving the reader to wonder whether routing ran at all.
	 */
	public function testTheDefaultRouteIsDumpedWhenThereWasNoChoice() {
		$dump = $this->dumpQuery( [], 'catapult' );

		$this->assertSame( [
			'winner' => 'cirrus_default',
			'context' => SearchProfileService::CONTEXT_DEFAULT,
			'profile' => 'default',
			'routes' => [
				'cirrus_default' => [
					'accepted' => true,
					'reason' => RouteDecision::REASON_DEFAULT,
					'context' => SearchProfileService::CONTEXT_DEFAULT,
					'score' => 1.0,
					'votes' => [],
				],
			],
		], $dump[Searcher::ROUTING_DUMP_KEY] );
		$this->assertArrayHasKey( Searcher::MAINSEARCH_MSEARCH_KEY, $dump );
	}

	/**
	 * The debug option is carried on the query, so an engine constructed with it reaches the
	 * semantic route without the request saying anything.
	 */
	public function testTheDebugOptionForcesTheSemanticRoute() {
		$dump = $this->dumpQuery(
			[
				CirrusConfigNames::DefaultSemanticProfile => 'semantic',
				// The wiki this runs against declares no semantic profile of its own, and
				// the route only exists for a wiki that can retrieve semantically.
				CirrusConfigNames::FullTextQueryBuilderProfiles => [
					'semantic' => [
						'builder_class' => SemanticSearchQueryBuilder::class,
						'settings' => [],
					],
				],
			],
			'catapult',
			CirrusDebugOptions::forSemanticSearchDumpInUnitTests()
		);

		$decision = $dump[Searcher::ROUTING_DUMP_KEY];
		$this->assertSame( 'semantic', $decision['winner'] );
		$this->assertSame( RouteDecision::REASON_FORCED, $decision['routes']['semantic']['reason'] );
		// The voters after the debug option are never asked.
		$this->assertSame( [ 'namespaces', 'query_classes', 'debug_option' ],
			array_keys( $decision['routes']['semantic']['votes'] ) );
	}
}
