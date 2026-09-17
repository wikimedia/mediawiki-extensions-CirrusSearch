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
		$bestScore = SearchQueryRoute::REJECT_ROUTE;

		/** @var SearchQueryRoute|null $best */
		$best = null;
		foreach ( $this->routes[$entryPoint] ?? [] as $route ) {
			$score = $route->score( $query );
			Assert::postcondition( $score >= 0 && $score <= 1.0, "SearchQueryRoute scores must be between 0.0 and 1.0" );
			if ( $score === SearchQueryRoute::REJECT_ROUTE ) {
				continue;
			}
			if ( $score === 1.0 && $bestScore === 1.0 ) {
				throw new SearchProfileException( "Two competing contexts " .
					// @phan-suppress-next-line PhanNonClassMethodCall $best always set when reaching this line
					"{$route->getProfileContext()} and {$best->getProfileContext()} " .
					" produced the max score" );
			}
			if ( $score > $bestScore ) {
				$best = $route;
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
