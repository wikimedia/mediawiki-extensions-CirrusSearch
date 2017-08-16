<?php

namespace CirrusSearch\Query;

use Config;
use CirrusSearch\Search\SearchContext;

/**
 * Matches "prefer-recent:" and then an optional floating point number <= 1 but
 * >= 0 (decay portion) and then an optional comma followed by another floating
 * point number >0 0 (half life).
 *
 * Examples:
 *  prefer-recent:
 *  prefer-recent:.6
 *  prefer-recent:0.5,.0001
 */
class PreferRecentFeature implements KeywordFeature {
	/**
	 * @var float Default number of days for the portion of the score effected
	 *  by this feature to be cut in half. Used when `prefer-recent:` is present
	 *  in the query without any arguments.
	 */
	private $halfLife;

	/**
	 * @var float Value between 0 and 1 indicating the default portion of the
	 *  score affected by this feature when not specified in the search term.
	 */
	private $unspecifiedDecay;

	/**
	 * @param Config $config
	 */
	public function __construct( Config $config ) {
		$this->halfLife = $config->get( 'CirrusSearchPreferRecentDefaultHalfLife' );
		$this->unspecifiedDecay = $config->get( 'CirrusSearchPreferRecentUnspecifiedDecayPortion' );
	}

	/**
	 * @param SearchContext $context
	 * @param string $term
	 * @return string
	 */
	public function apply( SearchContext $context, $term ) {
		return QueryHelper::extractSpecialSyntaxFromTerm(
			$context,
			$term,
			'/prefer-recent:(1|0?(?:\.\d+)?)?(?:,(\d*\.?\d+))? ?/',
			function ( $matches ) use ( $context ) {
				$decay = isset( $matches[1] ) && strlen( $matches[1] ) > 0
					? $decay = floatval( $matches[1] )
					: $decay = $this->unspecifiedDecay;

				$halfLife = isset( $matches[2] )
					? floatval( $matches[2] )
					: $this->halfLife;

				$context->setPreferRecentOptions( $decay, $halfLife );
				$context->addSyntaxUsed( 'prefer-recent' );

				return '';
			}
		);
	}
}
