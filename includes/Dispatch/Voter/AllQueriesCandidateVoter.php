<?php

namespace CirrusSearch\Dispatch\Voter;

use CirrusSearch\Search\SearchQuery;
use CirrusSearch\SearchConfig;

/**
 * Proposes the route for every query.
 *
 * A route only takes a query some voter proposed it for, so a route meant to catch whatever the
 * other voters allow through needs something that always proposes. Cirrus defaults are the
 * obvious case, and so is any route gated only by vetoes.
 *
 * @license GPL-2.0-or-later
 */
class AllQueriesCandidateVoter implements RouteVoter {
	/** @inheritDoc */
	public static function build( SearchConfig $config, array $params ): self {
		return new self();
	}

	public function vote( SearchQuery $query ): RouteVote {
		return RouteVote::Candidate;
	}
}
