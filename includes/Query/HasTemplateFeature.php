<?php

namespace CirrusSearch\Query;

use CirrusSearch\CrossSearchStrategy;
use CirrusSearch\Parser\AST\KeywordFeatureNode;
use CirrusSearch\Query\Builder\QueryBuildingContext;
use CirrusSearch\Search\Filters;
use CirrusSearch\Search\SearchContext;
use CirrusSearch\WarningCollector;
use Elastica\Query\AbstractQuery;
use Title;

/**
 * We emulate template syntax here as best as possible, so things in NS_MAIN
 * are prefixed with ":" and things in NS_TEMPATE don't have a prefix at all.
 * Since we don't actually index templates like that, munge the query here.
 */
class HasTemplateFeature extends SimpleKeywordFeature implements FilterQueryFeature {
	const MAX_CONDITIONS = 256;

	/**
	 * @return string[]
	 */
	protected function getKeywords() {
		return [ 'hastemplate' ];
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
		$filter = $this->doGetFilterQuery(
			$this->parseValue( $key, $value, $quotedValue, '', '', $context ) );
		return [ $filter, false ];
	}

	/**
	 * @param string $key
	 * @param string $value
	 * @param string $quotedValue
	 * @param string $valueDelimiter
	 * @param string $suffix
	 * @param WarningCollector $warningCollector
	 * @return array|false|null
	 */
	public function parseValue( $key, $value, $quotedValue, $valueDelimiter, $suffix, WarningCollector $warningCollector ) {
		$values = explode( '|', $value, self::MAX_CONDITIONS + 1 );
		if ( count( $values ) > self::MAX_CONDITIONS ) {
			$warningCollector->addWarning(
				'cirrussearch-feature-too-many-conditions',
				$key,
				self::MAX_CONDITIONS
			);
			$values = array_slice(
				$values,
				0,
				self::MAX_CONDITIONS
			);
		}
		$templates = [];
		foreach ( $values as $template ) {
			if ( strpos( $template, ':' ) === 0 ) {
				$template = substr( $template, 1 );
			} else {
				$title = Title::newFromText( $template );
				if ( $title && $title->getNamespace() === NS_MAIN ) {
					$template = Title::makeTitle( NS_TEMPLATE, $title->getDBkey() )
						->getPrefixedText();
				}
			}
			$templates[] = $template;
		}
		return [ 'templates' => $templates ];
	}

	/**
	 * @param KeywordFeatureNode $node
	 * @return CrossSearchStrategy
	 */
	public function getCrossSearchStrategy( KeywordFeatureNode $node ) {
		return CrossSearchStrategy::allWikisStrategy();
	}

	/**
	 * @param string[][] $parsedValue
	 * @return AbstractQuery
	 */
	protected function doGetFilterQuery( array $parsedValue ) {
		return Filters::booleanOr( array_map(
			function ( $v ) {
				return QueryHelper::matchPage( 'template', $v );
			},
			$parsedValue['templates']
		) );
	}

	/**
	 * @param KeywordFeatureNode $node
	 * @param QueryBuildingContext $context
	 * @return AbstractQuery|null
	 */
	public function getFilterQuery( KeywordFeatureNode $node, QueryBuildingContext $context ) {
		return $this->doGetFilterQuery( $node->getParsedValue() );
	}
}
