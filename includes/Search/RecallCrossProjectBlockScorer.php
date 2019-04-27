<?php

namespace CirrusSearch\Search;

/**
 * Score based on total hits : log(total_hits + 2)
 */
class RecallCrossProjectBlockScorer extends CrossProjectBlockScorer {
	/**
	 * @param string $prefix
	 * @param ResultSet $results
	 * @return float
	 */
	public function score( $prefix, ResultSet $results ) {
		return log( $results->getTotalHits() + 2 );
	}
}
