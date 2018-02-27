<?php

namespace CirrusSearch\Query;

use CirrusSearch\Search\Filters;
use CirrusSearch\Search\SearchContext;
use CirrusSearch\SearchConfig;

/**
 * Applies a filter against the title field in elasticsearch. When not negated
 * the term remains in the original query as a scoring signal. The term itself
 * is used as a QueryString query, so some advanced syntax like * and phrase
 * matches can be used. Note that quotes in the incoming query are maintained
 * in the generated filter.
 *
 * Examples:
 *   intitle:Foo
 *   intitle:Foo*
 *   intitle:"gold rush"
 *
 * Things that might seem like they would work, but don't. This is because the
 * quotes are maintained in the filter and in the top level query.
 *   intitle:"foo*"
 *   intitle:"foo OR bar"
 */
class InTitleFeature extends BaseRegexFeature {

	public function __construct( SearchConfig $config ) {
		parent::__construct( $config, [ 'title', 'redirect.title' ] );
	}

	/**
	 * @return string[]
	 */
	protected function getKeywords() {
		return [ 'intitle' ];
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
		$filter = Filters::intitle( $context->escaper(), $context, $quotedValue );

		return [ $filter, !$negated ];
	}
}
