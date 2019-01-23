<?php

namespace CirrusSearch\Fallbacks;

use CirrusSearch\Search\ResultSet;
use CirrusSearch\Search\SearchQuery;

/**
 * A fallback method is a way to interact (correct/fix/suggest a better query) with the search
 * results.
 *
 * Multiple methods can be chained together the order in which they are applied is determined
 * by the successApproximation method.
 *
 * The actual work is then done in the rewrite method where the method can actually change/augment
 * the current resultset.
 *
 * @package CirrusSearch\Fallbacks
 */
interface FallbackMethod {

	/**
	 * @param SearcherFactory $searcherFactory
	 * @param SearchQuery $query
	 * @return FallbackMethod
	 */
	public static function build( SearcherFactory $searcherFactory, SearchQuery $query );

	/**
	 * Approximation of the success of this fallback method
	 * this evaluation must be fast and not access remote resources.
	 *
	 * The score is interpreted as :
	 * - 1.0: the engine can blindly execute this one and discard any others
	 * 	 (saving respective calls to successApproximation of other methods)
	 * - 0.5: e.g. when no approximation is possible
	 * - 0.0: should not be tried (safe to skip costly work)
	 *
	 * The order of application (call to the rewrite method) is the order of these scores.
	 * If the score of multiple methods is equals the initialization order is kept.
	 *
	 * @param ResultSet $firstPassResults
	 * @return float
	 */
	public function successApproximation( ResultSet $firstPassResults );

	/**
	 * Rewrite the results,
	 * A costly call is allowed here, if nothing is to be done $previousSet
	 * must be returned.
	 *
	 * @param ResultSet $firstPassResults results of the initial query
	 * @param ResultSet $previousSet results returned by previous fallback method
	 * @return ResultSet
	 */
	public function rewrite( ResultSet $firstPassResults, ResultSet $previousSet );
}
