<?php

namespace CirrusSearch\Elastica;

/**
 * backport for https://github.com/ruflin/Elastica/pull/1224
 */
class MultiSearch extends \Elastica\Multi\Search {
	/**
	 * @param \Elastica\Search $search
	 *
	 * @return string
	 */
	protected function _getSearchData(\Elastica\Search $search) {
		$header = $this->_getSearchDataHeader($search);
		$header = (empty($header)) ? new \stdClass() : $header;
		$query = $search->getQuery();
		$toKeep = [
			'index' => true,
			'types' => true,
			'search_type' => true,
			'routing' => true,
			'preference' => true,
		];
		$queryOptions = array_diff_key( $header, $toKeep );
		$actualHeader = array_intersect_key( $header, $toKeep );;

		$data = \Elastica\JSON::stringify($actualHeader)."\n";
		$queryBody = $query->toArray() + $queryOptions;
		$data .= \Elastica\JSON::stringify($queryBody)."\n";
		return $data;
	}
}
