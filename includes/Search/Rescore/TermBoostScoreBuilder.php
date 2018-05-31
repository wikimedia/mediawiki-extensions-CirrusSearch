<?php

namespace CirrusSearch\Search\Rescore;

use CirrusSearch\Search\SearchContext;
use CirrusSearch\SearchConfig;
use Elastica\Query\FunctionScore;

/**
 * Boost score when certain field is matched with certain term.
 * Config:
 * [ 'field_name' => ['match1' => WEIGHT1, ...], ...]
 * @package CirrusSearch\Search
 */
class TermBoostScoreBuilder extends FunctionScoreBuilder {
	/** @var BoostedQueriesFunction */
	private $boostedQueries;

	/**
	 * @param SearchConfig|SearchContext $contextOrConfig
	 * @param float $weight
	 * @param array $profile
	 */
	public function __construct( $contextOrConfig, $weight, $profile ) {
		parent::__construct( $contextOrConfig, $weight );
		$queries = [];
		$weights = [];
		foreach ( $profile as $field => $matches ) {
			foreach ( $matches as $match => $matchWeight ) {
				$queries[] = new \Elastica\Query\Term( [ $field => $match ] );
				$weights[] = $matchWeight * $this->weight;
			}
		}
		$this->boostedQueries = new BoostedQueriesFunction( $queries, $weights );
	}

	public function append( FunctionScore $functionScore ) {
		$this->boostedQueries->append( $functionScore );
	}
}

class_alias( TermBoostScoreBuilder::class, 'CirrusSearch\Search\TermBoostScoreBuilder' );
