<?php

namespace CirrusSearch\Search;

use Elastica\ResultSet as ElasticaResultSet;

/**
 * Returns titles and makes no effort to figure out how the titles matched.
 */
class TitleResultsType extends BaseResultsType {
	/**
	 * @return array corresponding to Elasticsearch fields syntax
	 */
	public function getStoredFields() {
		return [];
	}

	/**
	 * @param array $extraHighlightFields
	 * @return array|null
	 */
	public function getHighlightingConfiguration( array $extraHighlightFields ) {
		return null;
	}

	/**
	 * @param ElasticaResultSet $resultSet
	 * @return mixed Set of search results, the types of which vary by implementation.
	 */
	public function transformElasticsearchResult( ElasticaResultSet $resultSet ) {
		$results = [];
		foreach ( $resultSet->getResults() as $r ) {
			$results[] = TitleHelper::makeTitle( $r );
		}
		return $results;
	}

	/**
	 * @return array
	 */
	public function createEmptyResult() {
		return [];
	}
}
