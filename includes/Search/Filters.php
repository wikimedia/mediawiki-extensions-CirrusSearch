<?php

namespace CirrusSearch\Search;
use Elastica;

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
	 * @param array(\Elastica\AbstractFilter) $mustFilters filters that must match all returned documents
	 * @param array(\Elastica\AbstractFilter) $mustNotFilters filters that must not match all returned documents
	 * @return null|\Elastica\AbstractFilter null if there are no filters or one that will execute
	 *     all of the provided filters
	 */
	public static function unify( $mustFilters, $mustNotFilters ) {
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
		foreach ( $mustNotFilters as $mustNot ) {
			if ( $mustNot->hasParam( 'script' ) ) {
				$scriptFilters[] = new \Elastica\Filter\BoolNot( $mustNot );
			} else {
				$nonScriptMustNot[] = $mustNot;
			}
		}

		$nonScript = self::unifyNonScript( $nonScriptMust, $nonScriptMustNot );
		$scriptFiltersCount = count( $scriptFilters );
		if ( $scriptFiltersCount === 0 ) {
			return $nonScript;
		}

		$boolAndFilter = new \Elastica\Filter\BoolAnd();
		if ( $nonScript === null ) {
			if ( $scriptFiltersCount === 1 ) {
				return $scriptFilters[ 0 ];
			}
		} else {
			$boolAndFilter->addFilter( $nonScript );
		}
		foreach ( $scriptFilters as $scriptFilter ) {
			$boolAndFilter->addFilter( $scriptFilter );
		}
		return $boolAndFilter;

	}

	/**
	 * Unify non-script filters into a single filter.
	 * @param array(\Elastica\AbstractFilter) $must filters that must be found
	 * @param array(\Elastica\AbstractFilter) $mustNot filters that must not be found
	 * @return null|\Elastica\AbstractFilter null if there are no filters or one that will execute
	 *     all of the provided filters
	 */
	private static function unifyNonScript( $mustFilters, $mustNotFilters ) {
		$mustFilterCount = count( $mustFilters );
		$mustNotFilterCount = count( $mustNotFilters );
		if ( $mustFilterCount + $mustNotFilterCount === 0 ) {
			return null;
		}
		if ( $mustFilterCount === 1 && $mustNotFilterCount == 0 ) {
			return $mustFilters[ 0 ];
		}
		if ( $mustFilterCount === 0 && $mustNotFilterCount == 1 ) {
			return new \Elastica\Filter\BoolNot( $mustNotFilters[ 0 ] );
		}
		$boolFilter = new \Elastica\Filter\Bool();
		foreach ( $mustFilters as $must ) {
			$boolFilter->addMust( $must );
		}
		foreach ( $mustNotFilters as $mustNot ) {
			$boolFilter->addMustNot( $mustNot );
		}
		return $boolFilter;
	}

  /**
   * Create a filter for insource: queries.  This was extracted from the big
   * switch block in Searcher.php.  This function is pure, deferring state
   * changes to the reference-updating return function.
   * @param \CirrusSearch\Search\Escaper $escaper
   * @param \CirrusSearch\Searcher $searcher
   * @param $value
   * @return a side-effecting function to update several references
   */
	public static function insource( $escaper, $searcher, $value ) {
		return self::insourceOrIntitle( $escaper, $searcher, $value, function () {
			return 'source_text.plain';
		});
	}

  /**
   * Create a filter for intitle: queries.  This was extracted from the big
   * switch block in Searcher.php.  This function is pure, deferring state
   * changes to the reference-updating return function.
   * @param \CirrusSearch\Search\Escaper $escaper
   * @param \CirrusSearch\Searcher $searcher
   * @param $value
   * @return a side-effecting function to update several references
   */
	public static function intitle( $escaper, $searcher, $value ) {
		return self::insourceOrIntitle( $escaper, $searcher, $value, function ( $queryString ) {
			if ( preg_match( '/[?*]/u', $queryString ) ) {
				return 'title.plain';
			} else {
				return 'title';
			}
		});
	}

	private static function insourceOrIntitle( $escaper, $searcher, $value, $fieldF ) {
		list( $queryString, $fuzzyQuery ) = $escaper->fixupWholeQueryString(
			$escaper->fixupQueryStringPart( $value ) );
		$field = $fieldF( $queryString );
		$query = new \Elastica\Query\QueryString( $queryString );
		$query->setFields( array( $field ) );
		$query->setDefaultOperator( 'AND' );
		$query->setAllowLeadingWildcard( false );
		$query->setFuzzyPrefixLength( 2 );
		$query->setRewrite( 'top_terms_128' );
		$wrappedQuery = $searcher->wrapInSaferIfPossible( $query, false );

		$updateReferences =
			function ( &$fuzzyQueryRef, &$filterDestinationRef, &$highlightSourceRef, &$searchContainedSyntaxRef ) use ( $fuzzyQuery, $wrappedQuery ) {
				$fuzzyQueryRef             = $fuzzyQuery;
				$filterDestinationRef[]    = new \Elastica\Filter\Query( $wrappedQuery );
				$highlightSourceRef[]      = array( 'query' => $wrappedQuery );
				$searchContainedSyntaxRef  = true;
			};

		return $updateReferences;
	}

}
