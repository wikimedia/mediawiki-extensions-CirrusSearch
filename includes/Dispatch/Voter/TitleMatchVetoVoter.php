<?php

namespace CirrusSearch\Dispatch\Voter;

use CirrusSearch\Search\SearchQuery;
use CirrusSearch\SearchConfig;

/**
 * Keeps a query off the route when it names a page that exists.
 *
 * A reader who types the title of an article wants that article, and the near-match
 * behavior lexical retrieval already has puts it first. A route that changes how the
 * results are retrieved can only do worse on such a query, which is why the veto stands
 * however long the query is. Semantic retrieval is the case this was written for, but the
 * voter says nothing about the route, so any profile can use it.
 *
 * The veto costs one near-match request to the search backend before the main query can
 * be built, which is why the voter is opt-in: it runs only for a route whose profile
 * lists it. A wiki that can't spend the extra round trip leaves it out of the profile.
 * Once listed, the lookup runs for every query that reaches it, including the queries no
 * other voter proposed the route for, because a vote is not known before it is asked for.
 * Put it last, after the voters that can settle the question without it.
 *
 * A lookup that fails votes to keep the query off the route. That is where the query
 * would have gone before the route existed, so a backend that can't answer leaves
 * behavior where it was rather than sending unmeasured traffic somewhere new.
 *
 * @license GPL-2.0-or-later
 */
class TitleMatchVetoVoter implements RouteVoter {
	private TitleMatchLookup $lookup;

	public function __construct( TitleMatchLookup $lookup ) {
		$this->lookup = $lookup;
	}

	/** @inheritDoc */
	public static function build( SearchConfig $config, array $params ): ?self {
		return new self( new SearcherTitleMatchLookup( $config ) );
	}

	public function vote( SearchQuery $query ): RouteVote {
		$matches = $this->lookup->matchesExistingTitle( $query );
		return $matches === false ? RouteVote::Abstain : RouteVote::Veto;
	}
}
