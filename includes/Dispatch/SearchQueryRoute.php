<?php

namespace CirrusSearch\Dispatch;

use CirrusSearch\Search\SearchQuery;

/**
 * For a given search engine entry point a SearchQueryRoute inspects the
 * SearchQuery to decide if we have a specialized execution method for that
 * query. The initial use case is to select semantic search for certain classes
 * of queries.
 *
 * The SearchQueryDispatchService evaluates the available routes and selects
 * the route with the highest score. It then assigns the profile context
 * provided by that route.
 *
 * SearchQueryRoutes are evaluated just after the SearchQuery is constructed
 * and before ES query building components are chosen.
 *
 * @see \CirrusSearch\Profile\SearchProfileService
 */
interface SearchQueryRoute {
	/**
	 * Decide if $query belongs on this route, and report the tie-breaking score.
	 *
	 * @param SearchQuery $query
	 * @return RouteDecision
	 */
	public function decide( SearchQuery $query ): RouteDecision;

	/**
	 * Name this route takes in the dispatch table and in debug output.
	 *
	 * @return string
	 */
	public function getName(): string;

	/**
	 * The entry point used in the search engine:
	 * - searchText
	 * - nearMatch
	 * - completionSearch
	 *
	 * @return string
	 */
	public function getSearchEngineEntryPoint(): string;

	/**
	 * The SearchProfile context to use when this route is chosen.
	 *
	 * @return string
	 */
	public function getProfileContext(): string;
}
