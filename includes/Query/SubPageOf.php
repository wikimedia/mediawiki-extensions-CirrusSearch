<?php

namespace CirrusSearch\Query;

use CirrusSearch\Search\SearchContext;

/**
 * subpagesof, find subpages of a given page
 * uses the prefix field, very similar to the prefix except
 * that it enforces a trailing / and is not a greedy keyword
 */
class SubPageOfFeature extends SimpleKeywordFeature {
	/**
	 * @return string[]
	 */
	protected function getKeywords() {
		return [ 'subpageof' ];
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
		if ( empty( $value ) ) {
			return [ null, false ];
		}
		if ( substr( $value, -1 ) != '/' ) {
			$value .= '/';
		}
		$query = new \Elastica\Query\MultiMatch();
		$query->setFields( [ 'title.prefix', 'redirect.title.prefix' ] );
		$query->setQuery( $value );
		return [ $query, false ];
	}
}
