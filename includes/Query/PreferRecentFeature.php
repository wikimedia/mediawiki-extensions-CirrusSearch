<?php

namespace CirrusSearch\Query;

use CirrusSearch\WarningCollector;
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
class PreferRecentFeature extends SimpleKeywordFeature {
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
	 * @return string[] The list of keywords this feature is supposed to match
	 */
	protected function getKeywords() {
		return [ "prefer-recent" ];
	}

	/**
	 * @return bool
	 */
	public function allowEmptyValue() {
		return true;
	}

	/**
	 * @param string $key
	 * @param string $value
	 * @param string $quotedValue
	 * @param string $valueDelimiter
	 * @param string $suffix
	 * @param WarningCollector $warningCollector
	 * @return array|null|false
	 */
	public function parseValue( $key, $value, $quotedValue, $valueDelimiter, $suffix, WarningCollector $warningCollector ) {
		$matches = [];
		$retValue = [];
		// FIXME: we should probably no longer accept the empty string and simply return false
		// instead of null
		if ( preg_match( '/^(1|0?(?:\.\d+)?)?(?:,(\d*\.?\d+))?$/', $value, $matches ) === 1 ) {
			if ( isset( $matches[1] ) && strlen( $matches[1] ) > 0 ) {
				$retValue['decay'] = floatval( $matches[1] );
			}

			if ( isset( $matches[2] ) ) {
				$retValue['halfLife'] = floatval( $matches[2] );
			}
			return count( $retValue ) > 0 ? $retValue : null;
		}
		return false;
	}

	/**
	 * Applies the detected keyword from the search term. May apply changes
	 * either to $context directly, or return a filter to be added.
	 *
	 * @param SearchContext $context
	 * @param string $key The keyword
	 * @param string $value The value attached to the keyword with quotes stripped and escaped
	 *  quotes un-escaped.
	 * @param string $quotedValue The original value in the search string, including quotes if used
	 * @param bool $negated Is the search negated? Not used to generate the returned AbstractQuery,
	 *  that will be negated as necessary. Used for any other building/context necessary.
	 * @return array Two element array, first an AbstractQuery or null to apply to the
	 *  query. Second a boolean indicating if the quotedValue should be kept in the search
	 *  string.
	 */
	protected function doApply( SearchContext $context, $key, $value, $quotedValue, $negated ) {
		$decay = $this->unspecifiedDecay;
		$halfLife = $this->halfLife;
		$parsedValue = $this->parseValue( $key, $value, $quotedValue, '', '', $context );
		if ( $parsedValue === false ) {
			$context->setPreferRecentOptions( $decay, $halfLife );
			return [ null, true ];
		} elseif ( $parsedValue === null ) {
			$context->setPreferRecentOptions( $this->unspecifiedDecay, $this->halfLife );
		} else {
			$context->setPreferRecentOptions( isset( $parsedValue['decay'] ) ? $parsedValue['decay'] : $this->unspecifiedDecay,
				isset( $parsedValue['halfLife'] ) ? $parsedValue['halfLife'] : $this->halfLife );
			return [ null, false ];
		}
	}
}
