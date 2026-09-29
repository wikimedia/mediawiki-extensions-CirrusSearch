<?php

namespace CirrusSearch\Elastica;

use Elastica\JSON;
use Elastica\Search as BaseSearch;

/**
 * Fork of Elastica\Search\Multi\Search to allow ignore_unavailable as a header.
 */
class MSearch extends \Elastica\Multi\Search {
	/**
	 * @var string[] This is this only array that actually needed a change
	 */
	private static $HEADER_OPTIONS = [
		'index',
		'types',
		'search_type',
		'routing',
		'preference',
		'ignore_unavailable',
	];

	/**
	 * Kept as-is from upstream but required nonetheless to access the changed {@link HEADER_OPTIONS}
	 * @inheritDoc
	 */
	protected function _getSearchData( BaseSearch $search ): string {
		$header = $this->_getSearchDataHeader( $search );

		$query = $search->getQuery();

		// Keep other query options as part of the search body
		$queryOptions =
			\array_diff_key( $search->getOptions(), \array_flip( self::$HEADER_OPTIONS ) );

		$data = JSON::stringify( $header === [] ? new \stdClass() : $header ) . "\n";
		$data .= JSON::stringify( $query->toArray() + $queryOptions ) . "\n";

		return $data;
	}

	/**
	 * Kept as-is from upstream but required nonetheless to access the changed {@link HEADER_OPTIONS}
	 * @inheritDoc
	 */
	protected function _getSearchDataHeader( BaseSearch $search ): array {
		$header = $search->getOptions();

		if ( $search->hasIndices() ) {
			$header['index'] = $search->getIndices();
		}

		// Filter options accepted in the "header"
		return \array_intersect_key( $header, \array_flip( self::$HEADER_OPTIONS ) );
	}
}
