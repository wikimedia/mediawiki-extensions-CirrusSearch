<?php

namespace CirrusSearch\Fallbacks;

use CirrusSearch\Search\ResultSet;
use CirrusSearch\Searcher;

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

	/**
	 * Check if any result in the response is fully highlighted on the title field
	 * @param \Elastica\ResultSet $results
	 * @return bool true if a result has its title fully highlighted
	 */
	public function resultContainsFullyHighlightedMatch( \Elastica\ResultSet $results ) {
		foreach ( $results as $result ) {
			$highlights = $result->getHighlights();
			// TODO: Should we check redirects as well?
			// If the whole string is highlighted then return true
			$regex = '/' . Searcher::HIGHLIGHT_PRE_MARKER . '.*?' . Searcher::HIGHLIGHT_POST_MARKER . '/';
			if ( isset( $highlights[ 'title' ] ) &&
				 !trim( preg_replace( $regex, '', $highlights[ 'title' ][ 0 ] ) ) ) {
				return true;
			}
		}
		return false;
	}
}
