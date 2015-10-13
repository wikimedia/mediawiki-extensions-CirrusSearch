<?php

namespace CirrusSearch\Search;

use CirrusSearch\SearchConfig;
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
	 * @var array of SearchTextQueryBuilder
	 */
	private $builders = array();

	/**
	 * @var SearchContext
	 */
	private $context;

	public function __construct( SearchContext $context ) {
		$this->context = $context;
		$this->builders[] = new SearchTextCommonTermsQueryBuilder( $context );
		$this->builders[] = new SearchTextQueryStringBuilder( $context );
	}

	/**
	 * Returns the best builder for this query.
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
	 * @param array $fields of string encoded as field_name^boost_value
	 * @param string $queryString the query
	 * @param integer $phraseSlop the phrase slop to use for phrase queries
	 * @return \Elastica\Query\AbstractQuery the query
	 */
	public function buildMainQuery( array $fields, $queryString, $phraseSlop );

	/**
	 * Builds the query used in the rescore phase
	 * @param array $fields of string encoded as field_name^boost_value
	 * @param string $queryString the query
	 * @param integer $phraseSlop the phrase slop to use for phrase queries
	 * @return \Elastica\Query\AbstractQuery the query
	 */
	public function buildRescoreQuery( array $fields, $queryString, $phraseSlop );

	/**
	 * Builds the query for highlighting
	 * @param array $fields of string encoded as field_name^boost_value
	 * @param string $queryString the query
	 * @param integer $phraseSlop the phrase slop to use for phrase queries
	 * @return \Elastica\Query\AbstractQuery the query
	 */
	public function buildHighlightQuery( array $fields, $queryString, $phraseSlop );


	/**
	 * Check if the query can be built by this builder
	 * @param array $fields of string encoded as field_name^boost_value
	 * @param string $queryString the query
	 * @param integer $phraseSlop the phrase slop to use for phrase queries
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
	 * {@inheritDoc}
	 */
	public function accept( $queryString ) {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function buildMainQuery( array $fields, $queryString, $phraseSlop ) {
		return $this->context->wrapInSaferIfPossible(
			$this->buildQueryString( $fields, $queryString, $phraseSlop ),
			false );
	}

	/**
	 * {@inheritDoc}
	 */
	public function buildHighlightQuery( array $fields, $queryString, $phraseSlop ) {
		return $this->context->wrapInSaferIfPossible(
			$this->buildQueryString( $fields, $queryString, $phraseSlop ),
			false );
	}

	/**
	 * {@inheritDoc}
	 */
	public function buildRescoreQuery( array $fields, $queryString, $phraseSlop ) {
		return $this->context->wrapInSaferIfPossible(
			$this->buildQueryString( $fields, $queryString, $phraseSlop ),
			true );
	}

	/**
	 * Builds a query based on QueryString syntax
	 * @param array $fields the fields
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
		return $query;
	}

}

/**
 * This builder builds a query based on the common terms query.  The builder
 * will build a commons term query for the plain fields.  Another common terms
 * query for the stem fields will be built if specified in the profile. A
 * simple/multimatch match query will be built otherwise.
 *
 * The common terms query is well suited for the plain field because stopwords
 * are not filtered, finding a cutoff freq is "relatively" easy.  Concerning
 * the commons term query and the stem field it's a bit trickier.  It can help
 * to determine what words the user would like to see in the results e.g.
 * 'what is snail slime made of'.
 * While 'what', 'is' and 'of' will be filtered by the stopword filter 'made'
 * can be considered as a high freq term and 'snail slime' will be required.
 * Showing best results with 'snail' and 'slimes' even if 'made' is not
 * present.
 *
 * This is not necessarily the case for all queries: e.g. 'interesting facts
 * about kennedy assassination'. In this case the most important words are
 * certainly 'kennedy' and 'assassination'.  But it appears that 'interesting'
 * has a lower docFreq than 'kennedy' on english wikipedia, so if the cutoff is
 * not properly set 'kennedy' might be considered as high freq while
 * 'interesting' will be a low freq.
 */
class SearchTextCommonTermsQueryBuilder extends SearchTextBaseQueryBuilder {
	/**
	 * The builder used for rescore and highlight query.
	 * @var SearchTextBaseQueryBuilder
	 */
	private $queryStringBuilder;

	/**
	 * The profile used to configure the queries
	 * @var array
	 */
	private $profile;

	public function __construct( SearchContext $config ) {
		parent::__construct( $config );
		$this->queryStringBuilder = new SearchTextQueryStringBuilder( $config );
		$this->profile = $this->context->getCommonTermsQueryProfile();
	}

	/**
	 * The query is accepted if the number of words in the query
	 * is greater or equal than min_query_terms defined in the
	 * CirrusSearchCommonTerm.
	 * {@inheritDoc}
	 */
	public function accept( $queryString ) {
		if ( !$this->context->isUseCommonTermsQuery() ) {
			return false;
		}

		// the Searcher class relies heavely on the QueryString syntax and
		// can generate QueryString syntax (i.e wildcards)
		// This builder cannot understand such syntax.
		if ( $this->context->isSearchContainedSyntax() ) {
			// It's likely a query string syntax...
			return false;
		}

		// XXX: this will work only for languages where space is a word separator
		// (note that query string has the same issue)
		// Ideal solution would be to move this code to java and reuse our lucene
		// analysis chain.
		// This is complex because the number of tokens depends on the field analyzer :
		// "what's the most dangerous snake"
		// is 5 tokens with the plain analyzer ("what's" remains "what's")
		// but 6 tokens with some language specific analyzer ("what's" is
		// "what" and "s" with the english analyzer)
		if ( str_word_count( $queryString ) < $this->profile['min_query_terms'] ) {
			return false;
		}

		// @fixme throw in a complete hack which will inform javascript that
		// this query was a common terms query. This should be removed when the
		// test is over, or a more generic way to report on the query type
		// should be invented.
		global $wgOut;
		$wgOut->addJsConfigVars( 'wgCirrusCommonTermsApplicable', true );

		$request = RequestContext::getMain()->getRequest();
		if ( $request !== null && $request->getVal( 'cirrusCommonTermsQueryControlGroup' ) === 'yes' ) {
			return false;
		}


		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function buildMainQuery( array $fields, $queryString, $phraseSlop ) {
		$plainFields = array();
		$stemFields = array();

		// Separate plain and stem fields first
		foreach( $fields as $f ) {
			list( $field, $boost ) = explode( '^', $f, 2 );
			$fieldInfo = array ( 'field' => $field, 'boost' => $boost );
			if ( Util::endsWith( $field, '.plain' ) ) {
				$plainFields[] = $fieldInfo;
			} else {
				$stemFields[] = $fieldInfo;
			}
		}
		$query = new \Elastica\Query\Bool();
		$query->setMinimumNumberShouldMatch( 1 );
		// We always build a common terms query for the plain field
		$this->attachCommonTermsClause( $query, $plainFields, $queryString, $this->profile );

		// We can use different types of query for the stem field.
		if ( count( $stemFields ) === 1 ) {
			$this->attachSingleFieldStemClause( $query, $stemFields[0], $queryString );
		} else {
			$this->attachMultiFieldsStemClause( $query, $stemFields, $queryString );
		}
		return $query;
	}

	/**
	 * Attach the query for the stem field. It will build a set of common
	 * terms query if use_common_terms is true for the stems clause or a
	 * multi match (cross_fields) if false.
	 *
	 * @param \Elastica\Query\Bool $query the boolean query to attach the new
	 * clause
	 * @param array $stemFields of boost field
	 * @param string $queryString the query
	 */
	private function attachMultiFieldsStemClause( \Elastica\Query\Bool $query, array $stemFields, $queryString ) {
		if ( $this->profile['stems_clause']['use_common_terms'] === true ) {
			$bool = new \Elastica\Query\Bool();
			$bool->setMinimumNumberShouldMatch( 1 );
			$this->attachCommonTermsClause( $bool, $stemFields, $queryString,
				$this->profile['stems_clause'] );
			$query->addShould( $bool );
		} else {
			$query->addShould( $this->buildCrossFields( $stemFields, $queryString,
				$this->profile['stems_clause']['min_should_match'] ) );
		}
	}

	/**
	 * Attach the query for the stem field. Will build a single common
	 * terms query if use_common_terms is true of a simple match if false.
	 *
	 * @param \Elastica\Query\Bool $query the boolean query to attach the
	 * new clause
	 * @param array $boostedField the boosted field
	 * @param string $queryString the query
	 */
	private function attachSingleFieldStemClause( \Elastica\Query\Bool $query, array $boostedField, $queryString ) {
		if ( $this->profile['stems_clause']['use_common_terms'] === true ) {
			$query->addShould( $this->buildOneCommonTermsClause( $boostedField, $queryString,
				$this->profile['stems_clause'] ) );
		} else {
			$query->addShould( $this->buildSimpleMatch( $boostedField, $queryString,
				$this->profile['stems_clause']['min_should_match'] ) );
		}
	}

	/**
	 * Attach a common terms query to $parent with a should.
	 * Note that if the all field is not used this can be more restrictive:
	 * for the query word1 word2 both words would have to appear in the
	 * same field. We cannot use similar techniques like cross_field of
	 * QueryString with multiple fields which allows both words to be
	 * in different fields.
	 *
	 * @param \Elastica\Query\Bool $parent
	 * @param array $boostedFields of boostedFields
	 * @param string $queryString the query
	 * @param array $profile the profile
	 */
	private function attachCommonTermsClause( \Elastica\Query\Bool $parent, array $boostedFields, $queryString, $profile ) {
		foreach( $boostedFields as $boostedField ) {
			$parent->addShould( $this->buildOneCommonTermsClause( $boostedField, $queryString, $profile ) );
		}
	}

	/**
	 * Builds a common terms query clause.
	 *
	 * @param array $boostedField the boosted field
	 * @param string $queryString the query
	 * @param array $profile the profile used by the common terms query
	 * @return \Elastica\Query\Common the common terms query.
	 */
	private function buildOneCommonTermsClause( array $boostedField, $queryString, array $profile ) {
		$common = new \Elastica\Query\Common( $boostedField['field'], $queryString, $profile['cutoff_freq'] );
		$common->setMinimumShouldMatch( array (
			'high_freq' => $profile['high_freq_min_should_match'],
			'low_freq' => $profile['low_freq_min_should_match']
		));
		$common->setBoost( $boostedField['boost'] );
		return $common;
	}

	/**
	 * Builds a multi match query with the cross_field type.
	 *
	 * @param array $boostedField of boosted fields
	 * @param string $queryString the query
	 * @param string $minShouldMatch the MinimumShouldMatch value
	 * @return \Elastica\Query\MultiMatch
	 */
	private function buildCrossFields( array $boostedFields, $queryString, $minShouldMatch ) {
		$fields = array();
		foreach( $boostedFields as $f ) {
			$fields[] = $f['field'] . '^' . $f['boost'];
		}
		$cross = new \Elastica\Query\MultiMatch();
		$cross->setQuery( $queryString );
		$cross->setFields( $fields );
		$cross->setType( 'cross_fields' );
		$cross->setMinimumShouldMatch( $minShouldMatch );
		return $cross;
	}

	/**
	 * Builds a simple match query.
	 *
	 * @param array $boostedField the boostedField
	 * @param string $queryString the query
	 * @param string $minShouldMatch the MinimumShouldMatch value
	 * @return \Elastica\Query\Match
	 */
	private function buildSimpleMatch( $boostedField, $queryString, $minShouldMatch ) {
		$match = new \Elastica\Query\Match();
		$match->setField( $boostedField['field'], array(
			'query' => $queryString,
			'minimum_should_match' => $minShouldMatch,
			'boost' => $boostedField['boost']
		) );
		return $match;
	}

	/**
	 * Use a SearchTextQueryStringBuilder with a default OR.
	 *
	 * {@inheritDoc}
	 */
	public function buildHighlightQuery( array $fields, $queryString, $phraseSlop ) {
		return $this->context->wrapInSaferIfPossible(
			$this->queryStringBuilder->buildQueryString( $fields, $queryString, $phraseSlop, 'OR' ),
			false );
	}

	/**
	 * Use a SearchTextQueryStringBuilder.
	 *
	 * {@inheritDoc}
	 */
	public function buildRescoreQuery( array $fields, $queryString, $phraseSlop ) {
		// already wrapped in safer
		return $this->queryStringBuilder->buildRescoreQuery( $fields, $queryString, $phraseSlop );
	}
}
