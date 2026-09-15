<?php

namespace CirrusSearch\Dispatch\Voter;

use CirrusSearch\Search\SearchQuery;
use CirrusSearch\SearchConfig;

/**
 * Keeps a query off a route that does not serve every namespace the query searches.
 *
 * A route serving a subset of namespaces cannot answer a query that reaches outside it, so this
 * is a veto rather than a preference. Nothing here proposes the route: a route gated only by
 * namespaces still needs something to propose it.
 *
 * A query that names no namespace at all is vetoed too. It could be anything, so a route that
 * does not serve everything cannot take it.
 *
 * @license GPL-2.0-or-later
 */
class NamespaceVetoVoter implements RouteVoter {
	/** Profile param holding the namespaces the route serves. */
	public const PARAM_NAMESPACES = 'namespaces';

	/** @var int[] */
	private array $namespaces;

	/**
	 * @param int[] $namespaces
	 */
	public function __construct( array $namespaces ) {
		$this->namespaces = $namespaces;
	}

	/** @inheritDoc */
	public static function build( SearchConfig $config, array $params ): ?self {
		$namespaces = $params[self::PARAM_NAMESPACES] ?? [];
		if ( $namespaces === [] ) {
			// A route that serves every namespace has nothing for this voter to check.
			return null;
		}
		return new self( $namespaces );
	}

	public function vote( SearchQuery $query ): RouteVote {
		$queried = $query->getNamespaces();
		if ( $queried === [] ) {
			return RouteVote::Veto;
		}
		if ( count( array_intersect( $this->namespaces, $queried ) ) !== count( $queried ) ) {
			return RouteVote::Veto;
		}
		return RouteVote::Abstain;
	}
}
