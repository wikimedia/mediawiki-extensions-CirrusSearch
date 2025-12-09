<?php

namespace CirrusSearch\Search;

use Elastica\ResultSet as ElasticaResultSet;

/**
 * Lightweight classes to describe specific result types we can return.
 *
 * @license GPL-2.0-or-later
 */
interface ResultsType {
	/**
	 * Get the source filtering to be used loading the result.
	 *
	 * @return false|string|array corresponding to Elasticsearch source filtering syntax
	 */
	public function getSourceFiltering();

	/**
	 * Get the fields to load.  Most of the time we'll use source filtering instead but
	 * some fields aren't part of the source.
	 *
	 * @return array corresponding to Elasticsearch fields syntax
	 */
	public function getFields();

	/**
	 * Get the highlighting configuration.
	 *
	 * @param array $extraHighlightFields configuration for how to highlight regex matches.
	 *  Empty if regex should be ignored.
	 * @return array|null highlighting configuration for elasticsearch
	 */
	public function getHighlightingConfiguration( array $extraHighlightFields );

	/**
	 * @param ElasticaResultSet $resultSet
	 * @return mixed Set of search results, the types of which vary by implementation.
	 */
	public function transformElasticsearchResult( ElasticaResultSet $resultSet );

	/**
	 * @return mixed Empty set of search results
	 */
	public function createEmptyResult();
}
