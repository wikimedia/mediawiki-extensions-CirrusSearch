<?php

namespace CirrusSearch\Query;

use CirrusSearch\Search\Filters;
use CirrusSearch\Search\SearchContext;
use CirrusSearch\SearchConfig;

/**
 * Handles non-regexp version of insource: keyword.  The value
 * (including possible quotes) is used as part of a QueryString
 * query while allows some bit of advanced syntax. Because quotes
 * are included, if present, multi-word queries containing AND or
 * OR do not work.
 *
 * Examples:
 *   insource:Foo
 *   insource:Foo*
 *   insource:"gold rush"
 *
 * Regex support:
 *   insource:/abc?/
 *
 * Things that don't work:
 *   insource:"foo*"
 *   insource:"foo OR bar"
 */
class InSourceFeature extends BaseRegexFeature {

	/**
	 * Source field
	 */
	const FIELD = 'source_text';

	/**
	 * @param SearchConfig $config
	 */
	public function __construct( SearchConfig $config ) {
		parent::__construct( $config, [ self::FIELD ] );
	}

	/**
	 * @return string[]
	 */
	protected function getKeywords() {
		return [ 'insource' ];
	}

	/**
	 * @param SearchContext $context
	 * @param string $key
	 * @param string $value
	 * @param string $quotedValue
	 * @param bool $negated
	 * @return array
	 */
	protected function doApply( SearchContext $context, $key, $value, $quotedValue, $negated ) {
		$filter = Filters::insource( $context->escaper(), $context, $quotedValue );
		$context->addHighlightField( self::FIELD, [ 'query' => $filter ] );
		return [ $filter, false ];
	}
}
