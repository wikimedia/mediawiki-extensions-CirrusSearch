<?php

namespace CirrusSearch\Dispatch;

use CirrusSearch\Profile\SearchProfileException;
use CirrusSearch\Profile\SearchProfileService;
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
		$bestScore = RouteDecision::REJECT_ROUTE;
		$decisions = [];

		/** @var SearchQueryRoute|null $best */
		$best = null;
		$winner = '';
		foreach ( $this->routes[$entryPoint] ?? [] as $route ) {
			$name = $this->nameOf( $route, $decisions );
			$decision = $this->fitsForcedProfiles( $query, $route )
				? $route->decide( $query )
				: $this->forcedProfileDecision( $route );
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
	 * down or there were none to ask.
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
		if ( !$this->fitsForcedProfiles( $query, $route ) ) {
			$decisions[$winner] = $this->forcedProfileDecision( $route );
			$none = new DefaultSearchQueryRoute( SearchProfileService::CONTEXT_NONE, $entryPoint,
				SearchProfileService::CONTEXT_NONE );
			return new DispatchDecision( $none, $none->getName(), $decisions, $this->profileName );
		}
		$decisions[$winner] = $route->decide( $query );
		return new DispatchDecision( $route, $winner, $decisions, $this->profileName );
	}

	/**
	 * Reject routes that do not support the requested forced profiles
	 */
	private function fitsForcedProfiles( SearchQuery $query, SearchQueryRoute $route ): bool {
		if ( !$query->hasForcedProfile() ) {
			return true;
		}
		$profileService = $query->getSearchConfig()->getProfileService();
		foreach ( $query->getForcedProfiles() as $type => $name ) {
			if ( !$profileService->profileFitsContext( $type, $name, $route->getProfileContext() ) ) {
				return false;
			}
		}
		return true;
	}

	private function forcedProfileDecision( SearchQueryRoute $route ): RouteDecision {
		return new RouteDecision( $route->getProfileContext(), false,
			RouteDecision::REASON_FORCED_PROFILE, RouteDecision::REJECT_ROUTE );
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
