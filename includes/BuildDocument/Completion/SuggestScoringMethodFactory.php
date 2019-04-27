<?php

namespace CirrusSearch\BuildDocument\Completion;

/**
 * Create certain suggestion scoring method, by name.
 */
class SuggestScoringMethodFactory {
	/**
	 * @param string $scoringMethod the name of the scoring method
	 * @return SuggestScoringMethod
	 */
	public static function getScoringMethod( $scoringMethod ) {
		switch ( $scoringMethod ) {
			case 'incomingLinks':
				return new IncomingLinksScoringMethod();
			case 'quality':
				return new QualityScore();
			case 'popqual':
				return new PQScore();
		}
		throw new \Exception( 'Unknown scoring method ' . $scoringMethod );
	}
}
