<?php

namespace CirrusSearch;

/**
 * PoolCounter type keys used by CirrusSearch. These name the entries operators
 * configure in $wgPoolCounterConf and are passed to the pool counter when
 * scheduling work (e.g. Util::doPoolCounterWork()).
 */
class PoolCounterKey {
	// Default full-text search pool.
	public const SEARCH = 'CirrusSearch-Search';
	// Expensive full-text queries (regex, deepcat) and the fallback pool.
	public const EXPENSIVE_FULL_TEXT = 'CirrusSearch-ExpensiveFullText';
	// Prefix search pool.
	public const PREFIX = 'CirrusSearch-Prefix';
	// More-like-this queries.
	public const MORE_LIKE = 'CirrusSearch-MoreLike';
	// Semantic search queries.
	public const SEMANTIC = 'CirrusSearch-Semantic';
	// Requests identified as automated traffic.
	public const AUTOMATED = 'CirrusSearch-Automated';
	// Completion suggester queries.
	public const COMPLETION = 'CirrusSearch-Completion';
	// Document build requests via the API.
	public const QUERY_BUILD_DOCUMENT = 'CirrusSearch-QueryBuildDocument';
	// Namespace name lookups.
	public const NAMESPACE_LOOKUP = 'CirrusSearch-NamespaceLookup';
}
