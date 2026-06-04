<?php

namespace CirrusSearch\Query\Builder;

use CirrusSearch\Parser\AST\KeywordFeatureNode;
use CirrusSearch\Search\Fetch\HighlightFieldGenerator;
use CirrusSearch\SearchConfig;

/**
 * WIP: figure out what we need when building
 * certainly some states built by some keyword
 * or some classification of the query
 */
interface QueryBuildingContext {

	/**
	 * @return SearchConfig
	 */
	public function getSearchConfig();

	/**
	 * @param KeywordFeatureNode $node
	 * @return array
	 */
	public function getKeywordExpandedData( KeywordFeatureNode $node );

	/**
	 * @return HighlightFieldGenerator
	 */
	public function getHighlightFieldGenerator(): HighlightFieldGenerator;

	/**
	 * @return bool Whether the query is in redirect scope (redirect mode). Mirrors
	 *  SearchContext::isRedirectScope() so the AST builder drops the same
	 *  redirect-scoped fields the live path does. False by default.
	 */
	public function isRedirectScope(): bool;
}
