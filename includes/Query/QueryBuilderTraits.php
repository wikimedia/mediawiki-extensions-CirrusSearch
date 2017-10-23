<?php

namespace CirrusSearch\Query;

use ApiUsageException;
use CirrusSearch;
use UsageException;

/**
 * Various utility functions that can be shared across cirrus query builders
 */
trait QueryBuilderTraits {
	/**
	 * @param string $term
	 * @throws ApiUsageException if the query is longer than {@link MAX_TITLE_SEARCH}
	 * @throws UsageException
	 */
	public function checkTitleSearchRequestLength( $term ) {
		$requestLength = mb_strlen( $term );
		if ( $requestLength > CirrusSearch::MAX_TITLE_SEARCH ) {
			if ( class_exists( ApiUsageException::class ) ) {
				throw ApiUsageException::newWithMessage(
					null,
					[ 'apierror-cirrus-requesttoolong', $requestLength, CirrusSearch::MAX_TITLE_SEARCH ],
					'request_too_long',
					[],
					400
				);
			} else {
				/** @suppress PhanDeprecatedClass */
				throw new UsageException( 'Prefix search request was longer than the maximum allowed length.' .
					" ($requestLength > " . CirrusSearch::MAX_TITLE_SEARCH . ')', 'request_too_long',
					400 );
			}
		}
	}
}
