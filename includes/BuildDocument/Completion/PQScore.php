<?php

namespace CirrusSearch\BuildDocument\Completion;

/**
 * Score that combines QualityScore and the pageviews statistics (popularity)
 */
class PQScore extends QualityScore {
	const QSCORE_WEIGHT = 1;
	const POPULARITY_WEIGHT = 0.4;
	// 0.04% of the total page views is the max we accept
	// @todo: tested on enwiki values only
	const POPULARITY_MAX = 0.0004;

	/**
	 * @return string[]
	 */
	public function getRequiredFields() {
		return array_merge( parent::getRequiredFields(), [ 'popularity_score' ] );
	}

	/**
	 * @param array $doc
	 * @return int
	 */
	public function score( array $doc ) {
		$score = $this->intermediateScore( $doc ) * self::QSCORE_WEIGHT;
		$pop = $doc['popularity_score'] ?? 0;
		if ( $pop > self::POPULARITY_MAX ) {
			$pop = 1;
		} else {
			$logBase = 1 + self::POPULARITY_MAX * $this->maxDocs;
			// log₁(x) is undefined
			if ( $logBase > 1 ) {
				// @fixme: rough log scale by using maxDocs...
				$pop = log( 1 + ( $pop * $this->maxDocs ), $logBase );
			} else {
				$pop = 0;
			}
		}

		$score += $pop * self::POPULARITY_WEIGHT;
		$score /= self::QSCORE_WEIGHT + self::POPULARITY_WEIGHT;
		return intval( $score * self::SCORE_RANGE );
	}
}
