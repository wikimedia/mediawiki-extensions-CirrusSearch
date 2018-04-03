<?php

namespace CirrusSearch\Search\Rescore;

use CirrusSearch\Search\SearchContext;
use Elastica\Query\FunctionScore;

/**
 * Builds a function using a custom numeric field and
 * parameters attached to a profile.
 * Uses the function field_value_factor
 */
class CustomFieldFunctionScoreBuilder extends FunctionScoreBuilder {
	/**
	 * @var array the field_value_factor profile
	 */
	private $profile;

	/**
	 * @param SearchContext $context
	 * @param float $weight
	 * @param array $profile
	 */
	public function __construct( SearchContext $context, $weight, $profile ) {
		parent::__construct( $context, $weight );
		if ( isset( $profile['factor'] ) ) {
			$profile['factor'] = $this->getOverriddenFactor( $profile['factor'] );
		}
		$this->profile = $profile;
	}

	public function append( FunctionScore $functionScore ) {
		if ( isset( $this->profile['factor'] ) && $this->profile['factor'] === 0.0 ) {
			// If factor is 0 this function score will have no impact.
			return;
		}
		$functionScore->addFunction( 'field_value_factor', $this->profile, null, $this->weight );
	}
}
