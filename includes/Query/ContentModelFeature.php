<?php

namespace CirrusSearch\Query;

use CirrusSearch\Search\SearchContext;
use Elastica\Query;

/**
 * Content model feature:
 *  contentmodel:wikitext
 * Selects only articles having this content model.
 */
class ContentModelFeature extends SimpleKeywordFeature {
	/**
	 * @return string[]
	 */
	protected function getKeywords() {
		return [ 'contentmodel' ];
	}

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
		$query = new Query\Match( 'content_model', [ 'query' => $value ] );

		return [ $query, false ];
	}
}
