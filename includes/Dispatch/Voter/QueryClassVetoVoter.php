<?php

namespace CirrusSearch\Dispatch\Voter;

use CirrusSearch\Search\SearchQuery;
use CirrusSearch\SearchConfig;

/**
 * Keeps a query off a route built for a particular kind of query.
 *
 * The classes come from the parsed query, so this asks what the query looks like rather than
 * what it says. A route naming classes serves those and nothing else, which is why a query of
 * some other class is vetoed rather than merely not proposed.
 *
 * @see \CirrusSearch\Parser\AST\ParsedQuery::isQueryOfClass()
 * @license GPL-2.0-or-later
 */
class QueryClassVetoVoter implements RouteVoter {
	/** Profile param holding the query classes the route serves. */
	public const PARAM_CLASSES = 'classes';

	/** @var string[] */
	private array $classes;

	/**
	 * @param string[] $classes
	 */
	public function __construct( array $classes ) {
		$this->classes = $classes;
	}

	/** @inheritDoc */
	public static function build( SearchConfig $config, array $params ): ?self {
		$classes = $params[self::PARAM_CLASSES] ?? [];
		if ( $classes === [] ) {
			// A route that serves every kind of query has nothing for this voter to check.
			return null;
		}
		return new self( $classes );
	}

	public function vote( SearchQuery $query ): RouteVote {
		$parsedQuery = $query->getParsedQuery();
		foreach ( $this->classes as $class ) {
			if ( $parsedQuery->isQueryOfClass( $class ) ) {
				return RouteVote::Abstain;
			}
		}
		return RouteVote::Veto;
	}
}
