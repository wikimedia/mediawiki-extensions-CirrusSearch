<?php

namespace CirrusSearch\Query;

use CirrusSearch\CrossSearchStrategy;
use CirrusSearch\Parser\AST\KeywordFeatureNode;
use CirrusSearch\Search\SearchContext;
use CirrusSearch\SearchConfig;
use CirrusSearch\WarningCollector;
use MediaWiki\Title\Title;

/**
 * Finds pages similar to another one.
 * Greedy keyword kept for BC purposes, MoreLikeThisFeature should be preferred.
 */
class MoreLikeFeature extends SimpleKeywordFeature implements LegacyKeywordFeature {
	use MoreLikeTrait;

	private const MORE_LIKE_THIS = 'morelike';

	/**
	 * @var SearchConfig
	 */
	private $config;

	public function __construct( SearchConfig $config ) {
		$this->config = $config;
	}

	/**
	 * @return bool
	 */
	public function greedy() {
		return true;
	}

	/**
	 * morelike is only allowed at the beginning of the query
	 * @return bool
	 */
	public function queryHeader() {
		return true;
	}

	/** @inheritDoc */
	protected function getKeywords() {
		return [ self::MORE_LIKE_THIS ];
	}

	/**
	 * @param string $key
	 * @param string $valueDelimiter
	 * @return string
	 */
	public function getFeatureName( $key, $valueDelimiter ) {
		return "more_like";
	}

	/**
	 * @param KeywordFeatureNode $node
	 * @return CrossSearchStrategy
	 */
	public function getCrossSearchStrategy( KeywordFeatureNode $node ) {
		// We depend on the db to fetch the title
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
		$context->setCacheTtl( $this->config->get( 'CirrusSearchMoreLikeThisTTL' ) );
		$titles = $this->doExpand( $key, $value, $context );
		if ( $titles === [] ) {
			$context->setResultsPossible( false );
			return [ null, false ];
		}
		$query = $this->buildMoreLikeQuery( $titles );

		// this erases the main query making it impossible to combine with
		// other keywords/search query. MoreLikeThisFeature addresses this problem.
		$context->setMainQuery( $query );

		// highlight snippets are not great so it's worth running a match all query
		// to save cpu cycles
		$context->setHighlightQuery( new \Elastica\Query\MatchAll() );

		return [ null, false ];
	}

	/**
	 * @param KeywordFeatureNode $node
	 * @param SearchConfig $config
	 * @param WarningCollector $warningCollector
	 * @return array|Title[]
	 */
	public function expand( KeywordFeatureNode $node, SearchConfig $config, WarningCollector $warningCollector ) {
		return $this->doExpand( $node->getKey(), $node->getValue(), $warningCollector );
	}

	public function getConfig(): SearchConfig {
		return $this->config;
	}
}
