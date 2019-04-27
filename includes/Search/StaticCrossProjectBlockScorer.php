<?php

namespace CirrusSearch\Search;

/**
 * Based on a static config, allows to give a fixed score to a particular
 * wiki
 */
class StaticCrossProjectBlockScorer extends CrossProjectBlockScorer {
	/**
	 * static weights
	 */
	private $staticScores;

	public function __construct( array $settings ) {
		parent::__construct( $settings );
		$this->staticScores = $settings + [ '__default__' => 1 ];
	}

	/**
	 * @param string $prefix
	 * @param ResultSet $results
	 * @return float
	 */
	public function score( $prefix, ResultSet $results ) {
		$staticScoreKey = '__default__';
		if ( isset( $this->staticScores[$prefix] ) ) {
			$staticScoreKey = $prefix;
		}
		return $this->staticScores[$staticScoreKey];
	}
}
