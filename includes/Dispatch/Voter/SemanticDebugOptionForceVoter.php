<?php

namespace CirrusSearch\Dispatch\Voter;

use CirrusSearch\Search\SearchQuery;
use CirrusSearch\SearchConfig;

/**
 * Puts a query on the route when the cirrusSemanticSearch debug option asks for it.
 *
 * This is how a developer reaches semantic retrieval on a wiki that routes nothing
 * automatically. It forces rather than proposes, so the voters after it are not asked. That
 * matters for more than tidiness: a request that has already said what it wants should not pay
 * for a voter that talks to the search backend only to be overruled.
 *
 * The option is read from the query rather than from the request, because that is where the
 * engine put it. A caller that constructed CirrusSearch with its own CirrusDebugOptions never
 * touched the request at all.
 *
 * This is the one voter that knows which route it serves. Listing it in a profile is a debug
 * affordance, not routing policy.
 *
 * @license GPL-2.0-or-later
 */
class SemanticDebugOptionForceVoter implements RouteVoter {
	/** @inheritDoc */
	public static function build( SearchConfig $config, array $params ): self {
		return new self();
	}

	public function vote( SearchQuery $query ): RouteVote {
		return $query->getDebugOptions()->isCirrusSemanticSearch()
			? RouteVote::Force
			: RouteVote::Abstain;
	}
}
