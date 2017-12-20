<?php

namespace CirrusSearch\Search;

use CirrusSearch\Profile\SearchProfileService;
use CirrusSearch\SearchConfig;
use CirrusSearch\Util;

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

/**
 * Factory that reads cirrus config and builds a CrossProjectBlockScorer
 */
class CrossProjectBlockScorerFactory {
	/**
	 * @param SearchConfig $searchConfig
	 * @return CrossProjectBlockScorer
	 */
	public static function load( SearchConfig $searchConfig ) {
		$profile = $searchConfig->getProfileService()
			->loadProfile( SearchProfileService::CROSS_PROJECT_BLOCK_SCORER );
		return static::loadScorer( $profile['type'], isset( $profile['settings'] ) ? $profile['settings'] : [] );
	}

	public static function loadScorer( $type, array $config ) {
		switch ( $type ) {
		case 'composite':
			return new CompositeCrossProjectBlockScorer( $config );
		case 'random':
			return new RandomCrossProjectBlockScorer( $config );
		case 'recall':
			return new RecallCrossProjectBlockScorer( $config );
		case 'static':
			return new StaticCrossProjectBlockScorer( $config );
		default:
			throw new \RuntimeException( 'Unknown CrossProjectBlockScorer type : ' . $type );
		}
	}
}

/**
 * Randomly ordered but consistent for a single user
 */
class RandomCrossProjectBlockScorer extends CrossProjectBlockScorer {
	public function __construct( array $settings ) {
		parent::__construct( $settings );
		mt_srand( hexdec( substr( Util::generateIdentToken(), 0, 8 ) ) );
	}

	/**
	 * @param string $prefix
	 * @param ResultSet $results
	 * @return float
	 */
	public function score( $prefix, ResultSet $results ) {
		return (float)mt_rand();
	}
}

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

/**
 * Composite, weighted sum of a list of subscorers
 */
class CompositeCrossProjectBlockScorer extends CrossProjectBlockScorer {
	private $scorers = [];

	public function __construct( array $settings ) {
		parent::__construct( $settings );
		foreach ( $settings as $type => $subSettings ) {
			$weight = isset( $subSettings['weight'] ) ? $subSettings['weight'] : 1;
			$scorerSettings = isset( $subSettings['settings'] ) ? $subSettings['settings'] : [];
			$scorer = CrossProjectBlockScorerFactory::loadScorer( $type, $scorerSettings );
			$this->scorers[] = [
				'weight' => $weight,
				'scorer' => $scorer,
			];
		}
	}

	/**
	 * @param string $prefix
	 * @param ResultSet $results
	 * @return float
	 */
	public function score( $prefix, ResultSet $results ) {
		$score = 0;
		foreach ( $this->scorers as $scorer ) {
			$score += $scorer['weight'] * $scorer['scorer']->score( $prefix, $results );
		}
		return $score;
	}
}
