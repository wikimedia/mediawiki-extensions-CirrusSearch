<?php

namespace CirrusSearch\Dispatch\Voter;

/**
 * What one voter says about putting one query on the route it votes for.
 *
 * A route is something a query opts in to, so a vote mostly has to say whether the voter wants
 * this query on the route, or wants it kept off whatever the other voters want. A voter with
 * nothing to say abstains.
 *
 * Force is the exception, for the case where something outside the query has already settled
 * the question, as a debug option does. It is the only vote that no later veto can overturn,
 * which is why it ends the pass. A voter that merely has a strong opinion wants Candidate.
 *
 * The case values are the strings that appear in debug output.
 *
 * @license GPL-2.0-or-later
 */
enum RouteVote: string {
	/** The voter has no opinion about this query. */
	case Abstain = 'abstain';

	/** The voter wants the query on the route, if no other voter vetoes it. */
	case Candidate = 'candidate';

	/** The voter keeps the query off the route. No other vote can overturn this one. */
	case Veto = 'veto';

	/** The voter puts the query on the route. No later veto applies. */
	case Force = 'force';
}
