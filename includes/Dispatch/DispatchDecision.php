<?php

namespace CirrusSearch\Dispatch;

/**
 * The outcome of dispatching a query: which route won, and what every route decided.
 *
 * Keeps track of all voters to improve debugability.
 *
 * @see SearchQueryDispatchService::dispatch()
 * @license GPL-2.0-or-later
 */
class DispatchDecision {
	private SearchQueryRoute $route;
	private string $winner;

	/** @var array<string, RouteDecision> */
	private array $decisions;

	private ?string $profileName;

	/**
	 * @param SearchQueryRoute $route the route that won
	 * @param string $winner name the winning route has in the dispatch table
	 * @param array<string, RouteDecision> $decisions every route's decision, keyed by route
	 *  name, in the order the routes were asked
	 * @param string|null $profileName dispatch profile the route table came from, null when the
	 *  table was assembled without one
	 */
	public function __construct(
		SearchQueryRoute $route,
		string $winner,
		array $decisions,
		?string $profileName = null
	) {
		$this->route = $route;
		$this->winner = $winner;
		$this->decisions = $decisions;
		$this->profileName = $profileName;
	}

	public function getRoute(): SearchQueryRoute {
		return $this->route;
	}

	public function getProfileContext(): string {
		return $this->route->getProfileContext();
	}

	/**
	 * @return string name the winning route has in the dispatch table
	 */
	public function getWinner(): string {
		return $this->winner;
	}

	/**
	 * @return array<string, RouteDecision> keyed by route name
	 */
	public function getDecisions(): array {
		return $this->decisions;
	}

	public function getProfileName(): ?string {
		return $this->profileName;
	}

	/**
	 * @return array the decision as it appears in debug output
	 */
	public function toArray(): array {
		return [
			'winner' => $this->winner,
			'context' => $this->getProfileContext(),
			'profile' => $this->profileName,
			'routes' => array_map(
				static fn ( RouteDecision $decision ) => $decision->toArray(),
				$this->decisions
			),
		];
	}
}
