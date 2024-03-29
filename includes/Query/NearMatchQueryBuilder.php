<?php

namespace CirrusSearch\Query;

use CirrusSearch\Search\SearchContext;
use Elastica\Query\MultiMatch;

/**
 * Build a query suited for exact title/redirect match.
 */
class NearMatchQueryBuilder {
	use QueryBuilderTraits;

	/**
	 * @param SearchContext $searchContext
	 * @param string $term the original search term
	 */
	public function build( SearchContext $searchContext, $term ) {
		if ( !$this->checkTitleSearchRequestLength( $term, $searchContext ) ) {
			return;
		}

		$searchContext->setOriginalSearchTerm( $term );
		// Elasticsearch seems to have trouble extracting the proper terms to highlight
		// from the default query we make so we feed it exactly the right query to highlight.
		$highlightQuery = new MultiMatch();
		$highlightQuery->setQuery( $term );
		$highlightQuery->setFields( [
			'title.near_match', 'redirect.title.near_match',
			'title.near_match_asciifolding', 'redirect.title.near_match_asciifolding',
		] );
		// Instead of using the highlight query we need to make one like it that uses the all_near_match field.
		$allQuery = new MultiMatch();
		$allQuery->setQuery( $term );
		$allQuery->setFields( [ 'all_near_match', 'all_near_match.asciifolding' ] );
		$searchContext->addFilter( $allQuery );
		$searchContext->setHighlightQuery( $highlightQuery );
		$searchContext->addSyntaxUsed( 'near_match' );
	}
}
