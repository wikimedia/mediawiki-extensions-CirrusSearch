<?php

namespace CirrusSearch\Query;

use CirrusSearch\Search\SearchContext;

/**
 * Parse a cirrus fulltext search query and build an elasticsearch query.
 */
interface FullTextQueryBuilder {
	/**
	 * Search articles with provided term.
	 *
	 * @param SearchContext $context
	 * @param string $term term to search
	 * @param boolean $showSuggestion should this search suggest alternative
	 * searches that might be better?
	 */
	public function build( SearchContext $searchContext, $term, $showSuggestion );
}
