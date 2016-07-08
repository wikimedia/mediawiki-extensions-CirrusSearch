<?php

namespace CirrusSearch\Query;

use CirrusSearch\Search\SearchContext;

interface KeywordFeature {
	/**
	 * Checks $term for usage of the feature, and applies necessary filters,
	 * rescores, etc. to the provided $context. The returned $term will be
	 * passed on to other keyword features, and eventually to an elasticsearch
	 * QueryString query.
	 *
	 * @param SearchContext $context
	 * @param string $term The input search query
	 * @return string The remaining search query after processing
	 */
	public function apply( SearchContext $context, $term );
}
