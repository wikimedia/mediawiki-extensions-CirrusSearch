<?php

namespace CirrusSearch\Query;

use CirrusSearch\Search\SearchContext;
use CirrusSearch\WarningCollector;
use Elastica\Query;

/**
 * File features:
 *  filebits:16  - bit depth
 *  filesize:>300 - size >= 300 kb
 *  filew:100,300 - search of 100 <= file_width <= 300
 * Selects only files of these specified features.
 */
class FileNumericFeature extends SimpleKeywordFeature {
	/**
	 * @return string[]
	 */
	protected function getKeywords() {
		return [ 'filesize', 'filebits', 'fileh', 'filew', 'fileheight', 'filewidth', 'fileres' ];
	}

	/**
	 * Map from feature names to keys
	 * @var string[]
	 */
	private $keyTable = [
		'filesize' => 'file_size',
		'filebits' => 'file_bits',
		'fileh' => 'file_height',
		'filew' => 'file_width',
		'fileheight' => 'file_height',
		'filewidth' => 'file_width',
		'fileres' => 'file_resolution',
	];

	/**
	 * @param SearchContext $context
	 * @param string $key The keyword
	 * @param string $value The value attached to the keyword with quotes stripped
	 * @param string $quotedValue The original value in the search string, including quotes
	 *     if used
	 * @param bool $negated Is the search negated? Not used to generate the returned
	 *     AbstractQuery, that will be negated as necessary. Used for any other building/context
	 *     necessary.
	 * @return array Two element array, first an AbstractQuery or null to apply to the
	 *  query. Second a boolean indicating if the quotedValue should be kept in the search
	 *  string.
	 */
	protected function doApply( SearchContext $context, $key, $value, $quotedValue, $negated ) {
		$field = $this->keyTable[$key];

		list( $sign, $number ) = $this->extractSign( $value );

		// filesize treats no sign as >, since exact file size matches make no sense
		if ( !$sign && $key === 'filesize' && strpos( $number, ',' ) === false ) {
			$sign = 1;
		}

		if ( !$this->validate( $context, $key, $value, $sign, $number ) ) {
			$context->setResultsPossible( false );
			return [ null, false ];
		}

		$query =
			$this->buildNumericQuery( $field, $sign, $number, ( $key === 'filesize' ) ? 1024 : 1 );

		return [ $query, false ];
	}

	/**
	 * Extract sign prefix which can be < or > or nothing.
	 * @param string $value
	 * @param int $default
	 * @return array Two element array, first the sign: 0 is equal, 1 is more, -1 is less,
	 *  then the number to be compared.
	 */
	protected function extractSign( $value, $default = 0 ) {
		if ( $value[0] == '>' || $value[0] == '<' ) {
			$sign = ( $value[0] == '>' ) ? 1 : - 1;
			return [ $sign, substr( $value, 1 ) ];
		} else {
			return [ $default, $value ];
		}
	}

	/**
	 * Validate that input arguments will construct a valid query
	 *
	 * @param WarningCollector $warningCollector
	 * @param string $key The matched query keyword
	 * @param string $value The original value provided by user
	 * @param int $sign
	 * @param string $number
	 * @return bool
	 */
	protected function validate( WarningCollector $warningCollector, $key, $value, $sign, $number ) {
		$valid = true;
		if ( $sign && strpos( $number, ',' ) !== false ) {
			$warningCollector->addWarning(
				'cirrussearch-file-numeric-feature-multi-argument-w-sign',
				$key,
				$number
			);
			$valid = false;
		} elseif ( $sign || strpos( $number, ',' ) === false ) {
			if ( !is_numeric( $number ) ) {
				$this->nanWarning( $warningCollector, $key, empty( $number ) ? $value : $number );
				$valid = false;
			}
		} else {
			$numbers = explode( ',', $number, 2 );
			if ( !is_numeric( $numbers[0] ) ) {
				$this->nanWarning( $warningCollector, $key, $numbers[0] );
				$valid = false;
			}
			if ( !is_numeric( $numbers[1] ) ) {
				$this->nanWarning( $warningCollector, $key, $numbers[1] );
				$valid = false;
			}
		}

		return $valid;
	}

	/**
	 * Adds a warning to the search context that the $key keyword
	 * was provided with the invalid value $notANumber.
	 *
	 * @param WarningCollector $warningCollector
	 * @param string $key
	 * @param string $notANumber
	 */
	protected function nanWarning( WarningCollector $warningCollector, $key, $notANumber ) {
		$warningCollector->addWarning(
			'cirrussearch-file-numeric-feature-not-a-number',
			$key,
			$notANumber
		);
	}

	/**
	 * Build a query which is either range match or exact match.
	 * @param string $field
	 * @param int $sign 0 is equal, 1 is more, -1 is less
	 * @param string $number number to compare to
	 * @param int $multiplier Multiplier for the number
	 * @return Query\AbstractQuery|null
	 */
	protected function buildNumericQuery( $field, $sign, $number, $multiplier = 1 ) {
		if ( $sign ) {
			$number = intval( $number );
			if ( $sign < 0 ) {
				$range = [ 'lte' => $number * $multiplier ];
			} else {
				$range = [ 'gte' => $number * $multiplier ];
			}
			return new Query\Range( $field, $range );
		} else {
			if ( strpos( $number, ',' ) !== false ) {
				$numbers = explode( ',', $number, 2 );
				return new Query\Range( $field, [
					'gte' => intval( $numbers[0] ) * $multiplier,
					'lte' => intval( $numbers[1] ) * $multiplier
				] );
			}
			$query = new Query\Match();
			$query->setFieldQuery( $field, (string)( $number * $multiplier ) );
		}
		return $query;
	}

}
