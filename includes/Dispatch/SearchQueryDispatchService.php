<?php

namespace CirrusSearch\Dispatch;

use CirrusSearch\Search\SearchQuery;

/**
 * The Search query dispatch service.
 * Based on a SearchQuery and the SearchQueryRoute that have been
 * declared find the best possible route for this query.
 *
 * All routes are evaluated the best one is returned.
 *  - If multiple routes gives equal score the first one wins
 *  - If multiple routes give the max score of 1 then the system fails
 *  - If no route selects the query it goes to the default route
 *
 * @see SearchQueryRoute
 */
interface SearchQueryDispatchService {
	/**
	 * Determine the best route for the $query.
	 *
	 * @param SearchQuery $query
	 * @return SearchQueryRoute
	 */
	public function bestRoute( SearchQuery $query ): SearchQueryRoute;

	/**
	 * Profile context of the route a query takes when it is not dispatched at all.
	 *
	 * Cross-wiki search wants the baseline of the wiki it searches, without applying the
	 * local wiki's routing rules to a wiki that may not have them.
	 *
	 * @param string $searchEngineEntryPoint
	 * @return string
	 */
	public function defaultProfileContext( string $searchEngineEntryPoint ): string;
}
