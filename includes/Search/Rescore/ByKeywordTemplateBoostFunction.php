<?php

namespace CirrusSearch\Search\Rescore;

class ByKeywordTemplateBoostFunction implements BoostFunctionBuilder {

	/**
	 * @var \CirrusSearch\Search\Rescore\BoostedQueriesFunction
	 */
	private $queries;

	public function __construct( array $boostTemplates ) {
		$queries = [];
		$weights = [];
		foreach ( $boostTemplates as $name => $weight ) {
			$match = new \Elastica\Query\MatchQuery();
			$match->setFieldQuery( 'template', $name );
			$weights[] = $weight;
			$queries[] = $match;
		}

		$this->queries = new BoostedQueriesFunction( $queries, $weights );
	}

	/**
	 * Append functions to the function score $container
	 */
	public function append( \Elastica\Query\FunctionScore $container ) {
		$this->queries->append( $container );
	}
}
