<?php

namespace CirrusSearch\Query;

use CirrusSearch\Search\SearchContext;

/**
 * Limits the search to the local wiki. Primarily this excludes results from
 * commons when searching the NS_FILE namespace. No value may be provided
 * along with this keyword, it is a simple boolean flag.
 */
class LocalFeature implements KeywordFeature {
	/**
	 * @param SearchContext $context
	 * @param string $term
	 * @return string
	 */
	public function apply( SearchContext $context, $term ) {
		return QueryHelper::extractSpecialSyntaxFromTerm(
			$context,
			$term,
			'/^\s*local:/',
			function () use ( $context ) {
				$context->setLimitSearchToLocalWiki( true );
				$context->addSyntaxUsed( 'local' );
				return '';
			}
		);
	}
}
