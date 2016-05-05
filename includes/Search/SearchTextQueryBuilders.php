<?php

namespace CirrusSearch\Search;

use CirrusSearch\Util;
use RequestContext;

/**
 * Set of builders to construct the full text search query.
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

/**
 * Factory of SearchTextQueryBuilder.
 * Maintains all the SearchTextQueryBuilder designed to
 * write the main full text query.
 */
class SearchTextQueryBuilderFactory {
	/**
	 * @var SearchTextQueryBuilder[]
	 */
	private $builders = array();

	/**
	 * @var SearchContext
	 */
	private $context;

	public function __construct( SearchContext $context ) {
		$this->context = $context;
		$this->builders[] = new SearchTextQueryStringBuilder( $context );
	}

	/**
	 * Returns the best builder for this query.
	 *
	 * @param string $queryString the query without special syntax
	 * @return SearchTextQueryBuilder
	 */
	public function getBuilder( $queryString ) {
		foreach( $this->builders as $builder ) {
			if( $builder->accept( $queryString ) ) {
				return $builder;
			}
		}

		throw new \Exception( "BUG: no query builder found for $queryString " );
	}
}

/**
 * Builds a full text search query
 */
interface SearchTextQueryBuilder {
	/**
	 * Builds the main query
	 *
	 * @param string[] $fields of string encoded as field_name^boost_value
	 * @param string $queryString the query
	 * @param integer $phraseSlop the phrase slop to use for phrase queries
	 * @return \Elastica\Query\AbstractQuery the query
	 */
	public function buildMainQuery( array $fields, $queryString, $phraseSlop );

	/**
	 * Builds the query used in the rescore phase
	 *
	 * @param string[] $fields of string encoded as field_name^boost_value
	 * @param string $queryString the query
	 * @param integer $phraseSlop the phrase slop to use for phrase queries
	 * @return \Elastica\Query\AbstractQuery the query
	 */
	public function buildRescoreQuery( array $fields, $queryString, $phraseSlop );

	/**
	 * Builds the query for highlighting
	 *
	 * @param string[] $fields of string encoded as field_name^boost_value
	 * @param string $queryString the query
	 * @param integer $phraseSlop the phrase slop to use for phrase queries
	 * @return \Elastica\Query\AbstractQuery the query
	 */
	public function buildHighlightQuery( array $fields, $queryString, $phraseSlop );


	/**
	 * Check if the query can be built by this builder
	 *
	 * @param string $queryString the query
	 * @return boolean true if this query can be built by this builder
	 */
	public function accept( $queryString );
}

/**
 * Base query builder
 */
abstract class SearchTextBaseQueryBuilder implements SearchTextQueryBuilder {
	/**
	 * @var SearchContext
	 */
	protected $context;

	/**
	 * @param SearchContext $context
	 */
	public function __construct( SearchContext $context ) {
		$this->context = $context;
	}
}

/**
 * Builds the query using the QueryString, this is the default builder
 * used by cirrus and uses a default AND between clause.
 * The query 'the query' and the fields all and all.plain will be like
 * (all:the OR all.plain:the) AND (all:query OR all.plain:query)
 */
class SearchTextQueryStringBuilder extends SearchTextBaseQueryBuilder {
	/**
	 * This builder will always return true.
	 *
	 * @param string $queryString
	 * @return bool
	 */
	public function accept( $queryString ) {
		return true;
	}

	/**
	 * @param string[] $fields
	 * @param string $queryString
	 * @param int $phraseSlop
	 * @return \Elastica\Query\AbstractQuery
	 */
	public function buildMainQuery( array $fields, $queryString, $phraseSlop ) {
		return $this->buildQueryString( $fields, $queryString, $phraseSlop );
	}

	/**
	 * @param string[] $fields
	 * @param string $queryString
	 * @param int $phraseSlop
	 * @return \Elastica\Query\AbstractQuery
	 */
	public function buildHighlightQuery( array $fields, $queryString, $phraseSlop ) {
		return $this->buildQueryString( $fields, $queryString, $phraseSlop );
	}

	/**
	 * @param string[] $fields
	 * @param string $queryString
	 * @param int $phraseSlop
	 * @return \Elastica\Query\AbstractQuery
	 */
	public function buildRescoreQuery( array $fields, $queryString, $phraseSlop ) {
		return $this->buildQueryString( $fields, $queryString, $phraseSlop );
	}

	/**
	 * Builds a query based on QueryString syntax
	 *
	 * @param string[] $fields the fields
	 * @param string $queryString the query
	 * @param integer $phraseSlop phrase slop
	 * @param string $defaultOperator the default operator AND or OR
	 * @return \Elastica\Query\QueryString
	 */
	public function buildQueryString( array $fields, $queryString, $phraseSlop, $defaultOperator = 'AND' ) {
		$query = new \Elastica\Query\QueryString( $queryString );
		$query->setFields( $fields );
		$query->setAutoGeneratePhraseQueries( true );
		$query->setPhraseSlop( $phraseSlop );
		$query->setDefaultOperator( $defaultOperator );
		$query->setAllowLeadingWildcard( $this->context->isAllowLeadingWildcards() );
		$query->setFuzzyPrefixLength( 2 );
		$query->setRewrite( 'top_terms_boost_1024' );
		$states = $this->context->getConfig()->get( 'CirrusSearchQueryStringMaxDeterminizedStates' );
		if ( isset( $states ) ) {
			// Requires ES 1.4+
			$query->setParam( 'max_determinized_states', $states );
		}
		return $query;
	}

}
