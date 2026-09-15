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

	/**
	 * @param SearchQueryRoute[][] $routes routes that select specialized query
	 *  routing.
	 * @param SearchQueryRoute[] $defaultRoutes route a query takes by default,
	 *  indexed by search engine entry point
	 */
	public function __construct( array $routes, array $defaultRoutes ) {
		$this->routes = $routes;
		$this->defaultRoutes = $defaultRoutes;
	}

	public function bestRoute( SearchQuery $query ): SearchQueryRoute {
		$entryPoint = $query->getSearchEngineEntryPoint();
		// forced profile's always apply against the default route
		if ( $query->hasForcedProfile() ) {
			return $this->defaultRoute( $entryPoint );
		}
		$bestScore = RouteDecision::REJECT_ROUTE;

		/** @var SearchQueryRoute|null $best */
		$best = null;
		$winner = '';
		foreach ( $this->routes[$entryPoint] ?? [] as $route ) {
			$decision = $route->decide( $query );
			if ( !$decision->isAccepted() ) {
				continue;
			}
			$score = $decision->getScore();
			if ( $score === 1.0 && $bestScore === 1.0 ) {
				throw new SearchProfileException( "Two competing routes " .
					"{$route->getName()} and $winner produced the max score" );
			}
			if ( $score > $bestScore ) {
				$best = $route;
				$winner = $route->getName();
				$bestScore = $score;
			}
		}
		return $best ?? $this->defaultRoute( $entryPoint );
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
}
