<?php

namespace CirrusSearch\Query;

use CirrusSearch\Search\Filters;
use CirrusSearch\Search\SearchContext;

/**
 * Filters the result set based on pages labeled with the provided language.
 * More than one language can be specified with commas and they will be
 * generated as an OR query.
 *
 * Examples:
 *   inlanguage:en
 *   inlanguage:fr,en
 */
class LanguageFeature extends SimpleKeywordFeature {
	/**
	 * Limit search to 20 languages. Arbitrarily chosen, but should be more
	 * than enough and some sort of limit has to be enforced.
	 */
	const QUERY_LIMIT = 20;

	/**
	 * @return string[]
	 */
	protected function getKeywords() {
		return [ 'inlanguage' ];
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
		$queries = [];

		$langs = explode( ',', $value );
		if ( count( $langs ) > self::QUERY_LIMIT ) {
			$context->addWarning(
				'cirrussearch-feature-too-many-conditions',
				$key,
				self::QUERY_LIMIT
			);
			$langs = array_slice( $langs, 0, self::QUERY_LIMIT );
		}
		foreach ( $langs as $lang ) {
			if ( strlen( trim( $lang ) ) > 0 ) {
				$query = new \Elastica\Query\Match();
				$query->setFieldQuery( 'language', $lang );
				$queries[] = $query;
			}
		}

		return [ Filters::booleanOr( $queries, false ), false ];
	}
}
