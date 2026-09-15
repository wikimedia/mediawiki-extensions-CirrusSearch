<?php

namespace CirrusSearch\Dispatch\Voter;

use CirrusSearch\Search\SearchQuery;

/**
 * Answers whether a query names a page that exists.
 *
 * The seam exists because the answer comes from the search backend, and the voter that
 * needs it has to stay testable without one.
 *
 * @see TitleMatchVetoVoter
 * @license GPL-2.0-or-later
 */
interface TitleMatchLookup {
	/**
	 * @param SearchQuery $query
	 * @return bool|null true when the query near-matches the title of an existing page,
	 *  false when it does not, and null when the lookup could not be run at all
	 */
	public function matchesExistingTitle( SearchQuery $query ): ?bool;
}
