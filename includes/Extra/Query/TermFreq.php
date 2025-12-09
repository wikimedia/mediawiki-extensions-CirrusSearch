<?php
/**
 * @license GPL-2.0-or-later
 */

namespace CirrusSearch\Extra\Query;

use Elastica\Query\AbstractQuery;
use Wikimedia\Assert\Assert;

/**
 * Filtering based on integer comparisons on the frequency of a term
 *
 * @link https://github.com/wikimedia/search-extra/blob/master/docs/term_freq_token_filter.md
 *
 * NOTE: only available if CirrusSearchWikimediaExtraPlugin['term_freq'] is set to true.
 */
class TermFreq extends AbstractQuery {

	/** @var string[] */
	private static $map = [
		'>' => 'gt',
		'>=' => 'gte',
		'<' => 'lt',
		'<=' => 'lte',
		'=' => 'eq',
	];

	/**
	 * @param string $field The name of the field to search
	 * @param string $term The term to search for
	 * @param string $operator A comparison operator. One of [ '<', '<=', '>', '>=', '=' ]
	 * @param int $number The number to compare against
	 */
	public function __construct( $field, $term, $operator, $number ) {
		Assert::parameter(
			isset( self::$map[$operator] ),
			$operator,
			"operator must be one of " . implode( ', ', array_keys( self::$map ) )
		);
		if ( $field !== '' && $term !== '' ) {
			$this->setParam( 'field', $field );
			$this->setParam( 'term', $term );
			$this->setParam( self::$map[ $operator ], $number );
		}
	}

}
