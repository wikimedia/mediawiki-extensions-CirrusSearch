<?php

namespace CirrusSearch\Search;

/**
 * Score an interwiki block
 */
abstract class CrossProjectBlockScorer {
	public function __construct( array $settings ) {
	}

	/**
	 * Compute a score for a given bloack of crossproject searchresults
	 * @param string $prefix
	 * @param ResultSet $results
	 * @return float the score for this block
	 */
	abstract public function score( $prefix, ResultSet $results );

	/**
	 * Reorder crossproject blocks using the $scorer
	 * @param array $resultsets array of ResultSet or empty array if the search was disabled
	 * @return array ResultSet reordered
	 */
	public function reorder( array $resultsets ) {
		$sortKeys = [];
		foreach ( $resultsets as $pref => $results ) {
			if ( $results instanceof ResultSet ) {
				$sortKeys[] = $this->score( $pref, $results );
			} else {
				$sortKeys[] = -1.0;
			}
		}
		array_multisort( $sortKeys, SORT_DESC, $resultsets );
		return $resultsets;
	}
}
