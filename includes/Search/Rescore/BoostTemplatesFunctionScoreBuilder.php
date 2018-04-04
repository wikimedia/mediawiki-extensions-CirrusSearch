<?php

namespace CirrusSearch\Search\Rescore;

use CirrusSearch\Search\SearchContext;
use CirrusSearch\Util;
use Elastica\Query\FunctionScore;

/**
 * Builds a set of functions with boosted templates
 * Uses a weight function with a filter for each template.
 * The list of boosted templates is read from SearchContext
 */
class BoostTemplatesFunctionScoreBuilder extends FunctionScoreBuilder {
	/**
	 * @var float[] Template boost values keyed by template name
	 */
	private $boostTemplates;

	/**
	 * @var float[][] Template boost values with wiki id at top level,
	 *  template at second level, and boost as the value.
	 */
	private $extraIndexBoostTemplates;

	/**
	 * @param SearchContext $context
	 * @param float $weight
	 */
	public function __construct( SearchContext $context, $weight ) {
		parent::__construct( $context->getConfig(), $weight );
		// Use the boosted template from query string if available
		$this->boostTemplates = $context->getBoostTemplatesFromQuery();
		// Use the boosted templates from extra indexes if available
		$this->extraIndexBoostTemplates = $context->getExtraIndexBoostTemplates();
		// empty array may be returned here in the case of a syntax error
		// @todo: verify that this is what we want: in case of a syntax error
		// we disable default boost templates.
		if ( $this->boostTemplates === null ) {
			// Fallback to default otherwise
			$this->boostTemplates = Util::getDefaultBoostTemplates( $context->getConfig() );
		}
	}

	public function append( FunctionScore $functionScore ) {
		if ( $this->boostTemplates ) {
			foreach ( $this->boostTemplates as $name => $weight ) {
				$match = new \Elastica\Query\Match();
				$match->setFieldQuery( 'template', $name );
				$functionScore->addWeightFunction( $weight * $this->weight, $match );
			}
		}
		foreach ( $this->extraIndexBoostTemplates as $wiki => $boostTemplates ) {
			foreach ( $boostTemplates as $name => $weight ) {
				$bool = new \Elastica\Query\BoolQuery();
				$bool->addMust( ( new \Elastica\Query\Match() )->setFieldQuery( 'wiki', $wiki ) );
				$bool->addMust( ( new \Elastica\Query\Match() )->setFieldQuery( 'template',
					$name ) );
				$functionScore->addWeightFunction( $weight * $this->weight, $bool );
			}
		}
	}
}
