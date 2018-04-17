<?php

namespace CirrusSearch\Search\Rescore;

use CirrusSearch\Search\SearchContext;
use Elastica\Query\FunctionScore;

/**
 * Builds a function that boosts incoming links
 * formula is log( incoming_links + 2 )
 */
class IncomingLinksFunctionScoreBuilder extends FunctionScoreBuilder {
	/**
	 * @param SearchContext $context
	 * @param float $weight
	 */
	public function __construct( SearchContext $context, $weight ) {
		parent::__construct( $context, $weight );
	}

	public function append( FunctionScore $functionScore ) {
		$functionScore->addFunction( 'field_value_factor', [
			'field' => 'incoming_links',
			'modifier' => 'log2p',
			'missing' => 0,
		] );
	}
}
