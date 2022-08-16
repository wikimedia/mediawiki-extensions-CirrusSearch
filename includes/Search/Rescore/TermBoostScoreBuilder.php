<?php

namespace CirrusSearch\Search\Rescore;

use CirrusSearch\SearchConfig;
use Elastica\Query\FunctionScore;
use Elastica\Query\MatchQuery;

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
	 * @param SearchConfig $config
	 * @param float $weight
	 * @param array $profile
	 */
	public function __construct( $config, $weight, $profile ) {
		parent::__construct( $config, $weight );
		$queries = [];
		$weights = [];
		foreach ( $profile as $field => $matches ) {
			foreach ( $matches as $match => $matchWeight ) {
				$queries[] = new MatchQuery( $field, $match );
				$weights[] = $matchWeight * $this->weight;
			}
		}
		$this->boostedQueries = new BoostedQueriesFunction( $queries, $weights );
	}

	public function append( FunctionScore $functionScore ) {
		$this->boostedQueries->append( $functionScore );
	}
}
