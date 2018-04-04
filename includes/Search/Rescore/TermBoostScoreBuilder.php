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
	/** @var array[] */
	private $fields;

	/**
	 * @param SearchConfig|SearchContext $contextOrConfig
	 * @param float $weight
	 * @param array $profile
	 */
	public function __construct( $contextOrConfig, $weight, $profile ) {
		parent::__construct( $contextOrConfig, $weight );
		$this->fields = $profile;
	}

	public function append( FunctionScore $functionScore ) {
		foreach ( $this->fields as $field => $matches ) {
			foreach ( $matches as $match => $matchWeight ) {
				$functionScore->addWeightFunction( $matchWeight * $this->weight,
					new \Elastica\Query\Term( [ $field => $match ] ) );
			}
		}
	}
}

class_alias( TermBoostScoreBuilder::class, 'CirrusSearch\Search\TermBoostScoreBuilder' );
