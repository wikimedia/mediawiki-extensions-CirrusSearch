<?php

namespace CirrusSearch\Query;

use CirrusSearch\CrossSearchStrategy;
use CirrusSearch\Parser\AST\KeywordFeatureNode;
use CirrusSearch\Query\Builder\QueryBuildingContext;
use CirrusSearch\Search\SearchContext;
use CirrusSearch\WarningCollector;
use Elastica\Query\AbstractQuery;
use Elastica\Query\MultiMatch;

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
		$parsedValue = $this->doParseValue( $value );
		if ( $parsedValue === null ) {
			return [ null, false ];
		}
		$q = $this->doGetFilterQuery( $parsedValue );
		return [ $q, false ];
	}

	/**
	 * @param KeywordFeatureNode $node
	 * @param QueryBuildingContext $context
	 * @return AbstractQuery|null
	 */
	public function getFilterQuery( KeywordFeatureNode $node, QueryBuildingContext $context ) {
		if ( $node->getParsedValue() === null ) {
			return null;
		}
		return $this->doGetFilterQuery( $node->getParsedValue() );
	}

	/**
	 * @param array $parsedValue
	 * @return AbstractQuery
	 */
	private function doGetFilterQuery( array $parsedValue ): AbstractQuery {
		$query = new MultiMatch();
		$query->setFields( [ 'title.prefix', 'redirect.title.prefix' ] );
		$query->setQuery( $parsedValue['prefix'] );
		return $query;
	}

	/**
	 * @inheritDoc
	 */
	public function parseValue( $key, $value, $quotedValue, $valueDelimiter, $suffix, WarningCollector $warningCollector ) {
		return $this->doParseValue( $value );
	}

	/**
	 * @param string $value
	 * @return array|null
	 */
	private function doParseValue( $value ) {
		if ( $value !== '' ) {
			$lastC = substr( $value, - 1 );
			if ( $lastC !== '/' && $lastC !== '*' ) {
				$value .= '/';
			} elseif ( $lastC === '*' ) {
				$value = substr( $value, 0, -1 );
			}
			return [ 'prefix' => $value ];
		}
		return null;
	}
}
