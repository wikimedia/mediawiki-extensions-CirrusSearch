<?php

namespace CirrusSearch\Fallbacks;

use CirrusSearch\Search\ResultSet;

trait FallbackMethodTrait {

	/**
	 * Check the number of total hits stored in $resultSet
	 * and return true if it's greater or equals than $threshold
	 * NOTE: inter wiki results are check
	 *
	 * @param ResultSet $resultSet
	 * @param int $threshold (defaults to 1).
	 *
	 * @see \SearchResultSet::getInterwikiResults()
	 * @see \SearchResultSet::SECONDARY_RESULTS
	 * @return bool
	 */
	public function resultsThreshold( ResultSet $resultSet, $threshold = 1 ) {
		if ( $resultSet->getTotalHits() >= $threshold ) {
			return true;
		}
		foreach ( $resultSet->getInterwikiResults( \SearchResultSet::SECONDARY_RESULTS ) as $resultSet ) {
			if ( $resultSet->getTotalHits() >= $threshold ) {
				return true;
			}
		}
		return false;
	}
}
