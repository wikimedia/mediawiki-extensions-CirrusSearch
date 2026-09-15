<?php

namespace CirrusSearch\Dispatch;

use CirrusSearch\Profile\SearchProfileException;
use CirrusSearch\Search\SearchQuery;
use Wikimedia\Assert\Assert;

class DefaultSearchQueryDispatchService implements SearchQueryDispatchService {
	/**
	 * List of routes per search engine entry point
	 * @var SearchQueryRoute[][] indexed by search engine entry point
	 */
	private array $routes;

	/**
	 * Route a query takes when no other route selects it
	 * @var SearchQueryRoute[] indexed by search engine entry point
	 */
	private array $defaultRoutes;

	/** @var string|null Dispatch profile the route table came from. */
	private ?string $profileName;

	/**
	 * @param SearchQueryRoute[][] $routes routes that select specialized query
	 *  routing.
	 * @param SearchQueryRoute[] $defaultRoutes route a query takes by default,
	 *  indexed by search engine entry point
	 * @param string|null $profileName dispatch profile the routes came from, for debug output
	 */
	public function __construct(
		array $routes,
		array $defaultRoutes,
		?string $profileName = null
	) {
		$this->routes = $routes;
		$this->defaultRoutes = $defaultRoutes;
		$this->profileName = $profileName;
	}

	public function dispatch( SearchQuery $query ): DispatchDecision {
		$entryPoint = $query->getSearchEngineEntryPoint();
		// forced profile's always apply against the default route
		if ( $query->hasForcedProfile() ) {
			return $this->defaultDecision( $entryPoint, $query, [] );
		}
		$bestScore = RouteDecision::REJECT_ROUTE;
		$decisions = [];

		/** @var SearchQueryRoute|null $best */
		$best = null;
		$winner = '';
		foreach ( $this->routes[$entryPoint] ?? [] as $route ) {
			$name = $this->nameOf( $route, $decisions );
			$decision = $route->decide( $query );
			$decisions[$name] = $decision;

			if ( !$decision->isAccepted() ) {
				continue;
			}
			$score = $decision->getScore();
			if ( $score === 1.0 ) {
				if ( $bestScore === 1.0 ) {
					throw new SearchProfileException( "Two competing routes " .
						"$name and $winner produced the max score" );
				}
				$bestScore = $score;
				$best = $route;
				$winner = $name;
			} elseif ( $score > $bestScore ) {
				$best = $route;
				$winner = $name;
				$bestScore = $score;
			}
		}
		if ( $best === null ) {
			return $this->defaultDecision( $entryPoint, $query, $decisions );
		}
		return new DispatchDecision( $best, $winner, $decisions, $this->profileName );
	}

	/**
	 * The decision when no election gave the query a route, because the routes turned it
	 * down, there were none to ask, or the request named a profile and there was nothing
	 * to elect.
	 *
	 * @param string $entryPoint
	 * @param SearchQuery $query
	 * @param array<string, RouteDecision> $decisions decisions collected so far
	 * @return DispatchDecision
	 */
	private function defaultDecision(
		string $entryPoint,
		SearchQuery $query,
		array $decisions
	): DispatchDecision {
		$route = $this->defaultRoute( $entryPoint );
		$winner = $this->nameOf( $route, $decisions );
		$decisions[$winner] = $route->decide( $query );
		return new DispatchDecision( $route, $winner, $decisions, $this->profileName );
	}

	public function defaultProfileContext( string $searchEngineEntryPoint ): string {
		return $this->defaultRoute( $searchEngineEntryPoint )->getProfileContext();
	}

	/**
	 * @param string $searchEngineEntryPoint
	 * @return SearchQueryRoute
	 */
	private function defaultRoute( string $searchEngineEntryPoint ): SearchQueryRoute {
		Assert::parameter( isset( $this->defaultRoutes[$searchEngineEntryPoint] ),
			'$searchEngineEntryPoint',
			"Unsupported search engine entry point $searchEngineEntryPoint" );
		return $this->defaultRoutes[$searchEngineEntryPoint];
	}

	/**
	 * Auto-name routes handling multiples of the same name as foo2, foo3, etc.
	 *
	 * @param SearchQueryRoute $route
	 * @param array<string, RouteDecision> $taken decisions collected so far
	 * @return string
	 */
	private function nameOf( SearchQueryRoute $route, array $taken ): string {
		$name = $route->getName();
		if ( !isset( $taken[$name] ) ) {
			return $name;
		}
		$suffix = 2;
		while ( isset( $taken["$name#$suffix"] ) ) {
			$suffix++;
		}
		return "$name#$suffix";
	}
}
