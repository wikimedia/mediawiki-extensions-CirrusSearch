<?php

namespace CirrusSearch\Query;

use ApiUsageException;
use CirrusSearch;

/**
 * Various utility functions that can be shared across cirrus query builders
 */
trait QueryBuilderTraits {
	/**
	 * @param string $term
	 * @throws ApiUsageException if the query is longer than {@link MAX_TITLE_SEARCH}
	 */
	public function checkTitleSearchRequestLength( $term ) {
		$requestLength = mb_strlen( $term );
		if ( $requestLength > CirrusSearch::MAX_TITLE_SEARCH ) {
			throw ApiUsageException::newWithMessage(
				null,
				[ 'apierror-cirrus-requesttoolong', $requestLength, CirrusSearch::MAX_TITLE_SEARCH ],
				'request_too_long',
				[],
				400
			);
		}
	}
}
