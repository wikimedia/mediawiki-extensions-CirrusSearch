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
	 * @param SearchContext $searchContext
	 * @param string $term term to search
	 * @param bool $showSuggestion should this search suggest alternative
	 * searches that might be better?
	 */
	public function build( SearchContext $searchContext, $term, $showSuggestion );

	/**
	 * Attempt to build a degraded query from the query already built into $context. Must be
	 * called *after* self::build().
	 *
	 * @param SearchContext $searchContext
	 * @return bool True if a degraded query was built
	 */
	public function buildDegraded( SearchContext $searchContext );
}
