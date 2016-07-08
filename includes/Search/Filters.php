<?php

namespace CirrusSearch\Search;

use Elastica;
use Elastica\Query\AbstractQuery;
use Elastica\Query\BoolQuery;
use GeoData\Coord;

/**
 * Utilities for dealing with filters.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */
class Filters {
	/**
	 * Merges lists of include/exclude filters into a single filter that
	 * Elasticsearch will execute efficiently.
	 *
	 * @param AbstractQuery[] $mustFilters filters that must match all returned documents
	 * @param AbstractQuery[] $mustNotFilters filters that must not match all returned documents
	 * @return null|AbstractQuery null if there are no filters or one that will execute
	 *     all of the provided filters
	 */
	public static function unify( array $mustFilters, array $mustNotFilters ) {
		// We want to make sure that we execute script filters last.  So we do these steps:
		// 1.  Strip script filters from $must and $mustNot.
		// 2.  Unify the non-script filters.
		// 3.  Build a BoolAnd filter out of the script filters if there are any.
		$scriptFilters = array();
		$nonScriptMust = array();
		$nonScriptMustNot = array();
		foreach ( $mustFilters as $must ) {
			if ( $must->hasParam( 'script' ) ) {
				$scriptFilters[] = $must;
			} else {
				$nonScriptMust[] = $must;
			}
		}
		$scriptMustNotFilter = new BoolQuery();
		foreach ( $mustNotFilters as $mustNot ) {
			if ( $mustNot->hasParam( 'script' ) ) {
				$scriptMustNotFilter->addMustNot( $mustNot );
			} else {
				$nonScriptMustNot[] = $mustNot;
			}
		}
		if ( $scriptMustNotFilter->hasParam( 'must_not' ) ) {
			$scriptFilters[] = $scriptMustNotFilter;
		}

		$nonScript = self::unifyNonScript( $nonScriptMust, $nonScriptMustNot );
		$scriptFiltersCount = count( $scriptFilters );
		if ( $scriptFiltersCount === 0 ) {
			return $nonScript;
		}

		$bool = new \Elastica\Query\BoolQuery();
		if ( $nonScript === null ) {
			if ( $scriptFiltersCount === 1 ) {
				return $scriptFilters[ 0 ];
			}
		} else {
			$bool->addFilter( $nonScript );
		}
		foreach ( $scriptFilters as $scriptFilter ) {
			$bool->addFilter( $scriptFilter );
		}
		return $bool;

	}

	/**
	 * Unify non-script filters into a single filter.
	 *
	 * @param AbstractQuery[] $mustFilters filters that must be found
	 * @param AbstractQuery[] $mustNotFilters filters that must not be found
	 * @return null|AbstractQuery null if there are no filters or one that will execute
	 *     all of the provided filters
	 */
	private static function unifyNonScript( array $mustFilters, array $mustNotFilters ) {
		$mustFilterCount = count( $mustFilters );
		$mustNotFilterCount = count( $mustNotFilters );
		if ( $mustFilterCount + $mustNotFilterCount === 0 ) {
			return null;
		}
		if ( $mustFilterCount === 1 && $mustNotFilterCount == 0 ) {
			return $mustFilters[ 0 ];
		}
		$bool = new \Elastica\Query\BoolQuery();
		foreach ( $mustFilters as $must ) {
			$bool->addMust( $must );
		}
		foreach ( $mustNotFilters as $mustNot ) {
			$bool->addMustNot( $mustNot );
		}
		return $bool;
	}

	/**
	 * Create a filter for insource: queries.  This was extracted from the big
	 * switch block in Searcher.php.  This function is pure, deferring state
	 * changes to the reference-updating return function.
	 *
	 * @param Escaper $escaper
	 * @param SearchContext $context
	 * @param string $value
	 * @return AbstractQuery
	 */
	public static function insource( Escaper $escaper, SearchContext $context, $value ) {
		return self::insourceOrIntitle( $escaper, $context, $value, function () {
			return 'source_text.plain';
		});
	}

	/**
	 * Create a filter for intitle: queries.  This was extracted from the big
	 * switch block in Searcher.php.
	 *
	 * @param Escaper $escaper
	 * @param SearchContext $context
	 * @param string $value
	 * @return AbstractQuery
	 */
	public static function intitle( Escaper $escaper, SearchContext $context, $value ) {
		return self::insourceOrIntitle( $escaper, $context, $value, function ( $queryString ) {
			if ( preg_match( '/[?*]/u', $queryString ) ) {
				return 'title.plain';
			} else {
				return 'title';
			}
		});
	}

	/**
	 * @param Escaper $escaper
	 * @param SearchContext $context
	 * @param string $value
	 * @param callable $fieldF
	 * @return AbstractQuery
	 */
	private static function insourceOrIntitle( Escaper $escaper, SearchContext $context, $value, $fieldF ) {
		list( $queryString, $fuzzyQuery ) = $escaper->fixupWholeQueryString(
			$escaper->fixupQueryStringPart( $value ) );
		$field = $fieldF( $queryString );
		$query = new \Elastica\Query\QueryString( $queryString );
		$query->setFields( array( $field ) );
		$query->setDefaultOperator( 'AND' );
		$query->setAllowLeadingWildcard( $escaper->getAllowLeadingWildcard() );
		$query->setFuzzyPrefixLength( 2 );
		$query->setRewrite( 'top_terms_boost_1024' );

		// @todo use a multi-return instead of passing in context?
		$context->setFuzzyQuery( $fuzzyQuery );

		return $query;
	}
}
