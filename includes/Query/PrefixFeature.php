<?php

namespace CirrusSearch\Query;

use CirrusSearch\CrossSearchStrategy;
use CirrusSearch\Parser\AST\KeywordFeatureNode;
use CirrusSearch\Query\Builder\QueryBuildingContext;
use CirrusSearch\WarningCollector;
use Elastica\Query\AbstractQuery;
use Elastica\Query\BoolQuery;
use Elastica\Query\Term;
use SearchEngine;
use CirrusSearch\Search\SearchContext;
use Wikimedia\Assert\Assert;

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
class PrefixFeature extends SimpleKeywordFeature implements FilterQueryFeature {
	/** @var string name of the keyword used in the syntax */
	const KEYWORD = 'prefix';

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
		return [ self::KEYWORD ];
	}

	/**
	 * @param KeywordFeatureNode $node
	 * @return CrossSearchStrategy
	 */
	public function getCrossSearchStrategy( KeywordFeatureNode $node ) {
		// Namespace handling seems to be wiki specific
		return CrossSearchStrategy::hostWikiOnlyStrategy();
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
		$parsedValue = $this->parseValue( $key, $value, $quotedValue, '', '', $context );
		$namespace = null;
		if ( isset( $parsedValue['namespace'] ) ) {
			$namespace = $parsedValue['namespace'];
		}
		// Re-activate once InputBox is fixed to generate proper prefix queries
		// $this->deprecationWarning( $context, $context->getNamespaces(), $namespace );
		$context->setNamespaces( $namespace !== null ? [ $namespace ] : $namespace );
		$prefixQuery = $this->buildQuery( $parsedValue['value'], $namespace );
		return [ $prefixQuery, false ];
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
		$trimQuote = '/^"([^"]*)"\s*$/';
		$value = preg_replace( $trimQuote, "$1", $value );
		// NS_MAIN by default
		$namespaces = [ NS_MAIN ];

		// Suck namespaces out of $value. Note that this overrides provided
		// namespace filters.
		$queryAndNamespace = SearchEngine::parseNamespacePrefixes( $value );
		if ( $queryAndNamespace !== false ) {
			// parseNamespacePrefixes returns the whole query if it's made of single namespace prefix
			$value = $value === $queryAndNamespace[0] ? '' : $queryAndNamespace[0];
			$namespaces = $queryAndNamespace[1];

			// Redo best effort quote trimming on the resulting value
			$value = preg_replace( $trimQuote, "$1", $value );
		}
		Assert::postcondition( $namespaces === null || count( $namespaces ) === 1,
			"namespace can only be an array with one value or null" );
		$value = trim( $value );
		// All titles in namespace
		if ( $value === '' ) {
			$value = null;
		}
		if ( $namespaces !== null ) {
			return [ 'namespace' => reset( $namespaces ), 'value' => $value ];
		} else {
			return [ 'value' => $value ];
		}
	}

	/**
	 * @param string $value
	 * @param int|null $namespace
	 * @return AbstractQuery|null null in the case of prefix:all:
	 */
	private function buildQuery( $value = null, $namespace = null ) {
		$nsFilter = null;
		$prefixQuery = null;
		if ( $value !== null ) {
			$prefixQuery = new \Elastica\Query\Match();
			$prefixQuery->setFieldQuery( 'title.prefix', $value );
		}
		if ( $namespace !== null ) {
			$nsFilter = new Term( [ 'namespace' => $namespace ] );
		}
		if ( $prefixQuery !== null && $nsFilter !== null ) {
			$query = new BoolQuery();
			$query->addMust( $prefixQuery );
			$query->addMust( $nsFilter );
			return $query;
		}

		return $nsFilter !== null ? $nsFilter : $prefixQuery;
	}

	/**
	 * @param array|null $searchNamespaces
	 * @param int|null $namespace
	 * @param WarningCollector $warningCollector
	 */
	private function deprecationWarning( WarningCollector $warningCollector, array $searchNamespaces = null, $namespace = null ) {
		if ( ( $searchNamespaces === [] || $searchNamespaces === null ) ) {
			return;
		}
		if ( $namespace !== null && in_array( $namespace, $searchNamespaces ) ) {
			return;
		}
		$warningCollector->addWarning( 'cirrussearch-keyword-prefix-ns-mismatch' );
	}

	/**
	 * @param KeywordFeatureNode $node
	 * @param QueryBuildingContext $context
	 * @return AbstractQuery|null
	 */
	public function getFilterQuery( KeywordFeatureNode $node, QueryBuildingContext $context ) {
		$namespace = null;

		if ( isset( $node->getParsedValue()['namespace'] ) ) {
			$namespace = $node->getParsedValue()['namespace'];
		}
		return $this->buildQuery( $node->getParsedValue()['value'], $namespace );
	}
}
