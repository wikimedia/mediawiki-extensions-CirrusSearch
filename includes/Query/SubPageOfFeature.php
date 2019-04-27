<?php

namespace CirrusSearch\Query;

use CirrusSearch\CrossSearchStrategy;
use CirrusSearch\Parser\AST\KeywordFeatureNode;
use CirrusSearch\Query\Builder\QueryBuildingContext;
use CirrusSearch\Search\SearchContext;
use Elastica\Query\AbstractQuery;

/**
 * subpagesof, find subpages of a given page
 * uses the prefix field, very similar to the prefix except
 * that it enforces a trailing / and is not a greedy keyword
 */
class SubPageOfFeature extends SimpleKeywordFeature implements FilterQueryFeature {
	/**
	 * @return string[]
	 */
	protected function getKeywords() {
		return [ 'subpageof' ];
	}

	/**
	 * @param KeywordFeatureNode $node
	 * @return CrossSearchStrategy
	 */
	public function getCrossSearchStrategy( KeywordFeatureNode $node ) {
		return CrossSearchStrategy::allWikisStrategy();
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
		return [ $this->doGetFilterQuery( $value ), false ];
	}

	/**
	 * @param KeywordFeatureNode $node
	 * @param QueryBuildingContext $context
	 * @return AbstractQuery|null
	 */
	public function getFilterQuery( KeywordFeatureNode $node, QueryBuildingContext $context ) {
		return $this->doGetFilterQuery( $node->getValue() );
	}

	/**
	 * @param string $value
	 * @return \Elastica\Query\MultiMatch|null
	 */
	private function doGetFilterQuery( $value ) {
		$query = null;
		if ( $value !== '' ) {
			if ( substr( $value, - 1 ) != '/' ) {
				$value .= '/';
			}
			$query = new \Elastica\Query\MultiMatch();
			$query->setFields( [ 'title.prefix', 'redirect.title.prefix' ] );
			$query->setQuery( $value );
		}

		return $query;
	}
}
