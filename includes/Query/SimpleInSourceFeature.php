<?php

namespace CirrusSearch\Query;

use CirrusSearch\Search\Filters;
use CirrusSearch\Search\SearchContext;

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
 * Things that don't work:
 *   insource:"foo*"
 *   insource:"foo OR bar"
 */
class SimpleInSourceFeature extends SimpleKeywordFeature {
	/**
	 * @return string[]
	 */
	protected function getKeywords() {
		return ['insource'];
	}

	/**
	 * @param SearchContext $context
	 * @param string $key The keyword
	 * @param string $value The value attached to the keyword with quotes stripped
	 * @param string $quotedValue The original value in the search string, including quotes if used
	 * @param bool $negated Is the search negated? Not used to generate the returned AbstractQuery,
	 *  that will be negated as necessary. Used for any other building/context necessary.
	 * @return array Two element array, first an AbstractQuery or null to apply to the
	 *  query. Second a boolean indicating if the quotedValue should be kept in the search
	 *  string.
	 */
	protected function doApply( SearchContext $context, $key, $value, $quotedValue, $negated ) {
		$filter = Filters::insource( $context->escaper(), $context, $quotedValue );
		$context->addHighlightSource( [ 'query' => $filter ] );

		return [ $filter, false ];
	}
}
