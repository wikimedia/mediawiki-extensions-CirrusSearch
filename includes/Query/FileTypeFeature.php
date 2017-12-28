<?php

namespace CirrusSearch\Query;

use CirrusSearch\Search\SearchContext;
use Elastica\Query;

/**
 * File type features:
 *  filetype:bitmap
 *  filemime:application/pdf
 * Selects only files of these specified features.
 */
class FileTypeFeature extends SimpleKeywordFeature {
	/**
	 * @return string[]
	 */
	protected function getKeywords() {
		return [ 'filetype','filemime' ];
	}

	/**
	 * @param SearchContext $context
	 * @param string $key The keyword
	 * @param string $value The value attached to the keyword with quotes stripped
	 * @param string $quotedValue The original value in the search string, including quotes
	 *     if used
	 * @param bool $negated Is the search negated? Not used to generate the returned
	 *     AbstractQuery, that will be negated as necessary. Used for any other building/context
	 *     necessary.
	 * @return array Two element array, first an AbstractQuery or null to apply to the
	 *  query. Second a boolean indicating if the quotedValue should be kept in the search
	 *  string.
	 */
	protected function doApply( SearchContext $context, $key, $value, $quotedValue, $negated ) {
		if ( $key == 'filetype' ) {
			$aliases = $context->getConfig()->get( 'CirrusSearchFiletypeAliases' );
			if ( is_array( $aliases ) && isset( $aliases[$value] ) ) {
				$value = $aliases[$value];
			}
			$query = new Query\Match( 'file_media_type', [ 'query' => $value ] );
		} else {
			if ( $value !== $quotedValue ) {
				// If used with quotes we create a more precise phrase query
				$query = new Query\MatchPhrase( 'file_mime', $value );
			} else {
				$query = new Query\Match( 'file_mime', [ 'query' => $value ] );
				$query->setFieldOperator( 'file_mime', 'AND' );
			}
		}

		return [ $query, false ];
	}
}
