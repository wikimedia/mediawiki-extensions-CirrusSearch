<?php

namespace CirrusSearch\Search\Rescore;

use Elastica\Query\FunctionScore;

/**
 * Builds a script score boost documents on the timestamp field.
 * Reads its param from SearchContext: preferRecentDecayPortion and preferRecentHalfLife
 * Can be initialized by config for full text and by special syntax in user query
 */
class PreferRecentFunctionScoreBuilder extends FunctionScoreBuilder {
	public function append( FunctionScore $functionScore ) {
		if ( !$this->context->hasPreferRecentOptions() ) {
			return;
		}
		// Convert half life for time in days to decay constant for time in milliseconds.
		$decayConstant = log( 2 ) / $this->context->getPreferRecentHalfLife() / 86400000;
		$parameters = [
			'decayConstant' => $decayConstant,
			'decayPortion' => $this->context->getPreferRecentDecayPortion(),
			'nonDecayPortion' => 1 - $this->context->getPreferRecentDecayPortion(),
			'now' => time() * 1000,
		];

		// e^ct where t is last modified time - now which is negative
		$exponentialDecayExpression = "exp(decayConstant * (doc['timestamp'].value - now))";
		if ( $this->context->getPreferRecentDecayPortion() !== 1.0 ) {
			$exponentialDecayExpression =
				"$exponentialDecayExpression * decayPortion + nonDecayPortion";
		}
		$functionScore->addScriptScoreFunction( new \Elastica\Script\Script( $exponentialDecayExpression,
			$parameters, 'expression' ), null, $this->weight );
	}
}
