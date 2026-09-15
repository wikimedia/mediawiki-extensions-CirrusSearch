<?php

namespace CirrusSearch\Dispatch\Voter;

use CirrusSearch\Search\SearchQuery;
use CirrusSearch\SearchConfig;

/**
 * One independent signal in the choice of whether a query belongs on a route.
 *
 * A route's entry in the query dispatch profile lists the voters to ask and the order to ask
 * them in. Each voter sees the complete query and returns a RouteVote, and the route aggregates
 * the votes. A voter must not make assumptions about the other voters in the entry, because the
 * wiki can add, remove and reorder them, nor about which route it is voting for, because the
 * same voter serves any route whose entry lists it.
 *
 * @see \CirrusSearch\Dispatch\VotedSearchQueryRoute
 * @license GPL-2.0-or-later
 */
interface RouteVoter {
	/**
	 * Build the voter from its profile entry.
	 *
	 * A voter that reads a $wgCirrusSearch* setting takes the name of that setting from
	 * $params rather than naming it itself, so that the binding of a setting to a route
	 * stays in the profile.
	 *
	 * @param SearchConfig $config config of the wiki being searched
	 * @param array $params params of this voter's entry in the dispatch profile
	 * @return RouteVoter|null the voter, or null when it can't work with this config (the
	 *  setting it was pointed at is unset, say) and must be left out of the route
	 */
	public static function build( SearchConfig $config, array $params ): ?RouteVoter;

	/**
	 * Vote on $query. This must be the voter's complete opinion: the route asks once.
	 */
	public function vote( SearchQuery $query ): RouteVote;
}
