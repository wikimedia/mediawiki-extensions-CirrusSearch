<?php

namespace CirrusSearch\Query;

use SearchEngine;
use CirrusSearch\Search\SearchContext;

/**
 * Handles the prefix: keyword for matching titles. Can be used to
 * specify a namespace, a prefix of the title, or both. Note that
 * unlike other keyword features this greedily uses everything after
 * the prefix: keyword, so must be used at the end of the query. Also
 * note that this will override namespace filters previously applied
 * to the SearchContext.
 *
 * Examples:
 *   prefix:Calif
 *   prefix:Talk:
 *   prefix:Talk:Calif
 *   prefix:California Cou
 *   prefix:"California Cou"
 */
class PrefixFeature extends SimpleKeywordFeature {

	/**
	 * @return bool
	 */
	public function greedy() {
		return true;
	}

	/**
	 * @return string[]
	 */
	protected function getKeywords() {
		return [ "prefix" ];
	}

	/**
	 * @param SearchContext $context
	 * @param string $key
	 * @param string $value
	 * @param string $quotedValue
	 * @param bool $negated
	 * @return array
	 */
	protected function doApply( SearchContext $context, $key, $value, $quotedValue, $negated ) {
		// XXX: only works because it's greedy
		$context->addSuggestSuffix( ' prefix:' . $value );
		// best effort quote trimming in case the query is simply wrapped in quotes
		// but ignores corner/ambiguous cases
		$trimQuote = '/^"([^"]*)"\s*$/';
		$value = preg_replace( $trimQuote, "$1", $value );
		// NS_MAIN by default
		$namespaces = [ NS_MAIN ];

		// Suck namespaces out of $value. Note that this overrides provided
		// namespace filters.
		$queryAndNamespace = SearchEngine::parseNamespacePrefixes( $value );
		if ( $queryAndNamespace !== false ) {
			$value = $queryAndNamespace[0];
			$namespaces = $queryAndNamespace[1];
			// Redo best effort quote trimming on the resulting value
			$value = preg_replace( $trimQuote, "$1", $value );
		}
		$value = trim( $value );
		$context->setNamespaces( $namespaces );
		if ( strlen( $value ) === 0 ) {
			return [ null, false ];
		}

		// If the namespace prefix wasn't the entire prefix filter then add a filter for the title
		$prefixQuery = null;
		if ( strpos( $value, ':' ) !== strlen( $value ) - 1 ) {
			$prefixQuery = new \Elastica\Query\Match();
			$prefixQuery->setFieldQuery( 'title.prefix', $value );
		}

		return [ $prefixQuery, false ];
	}
}
