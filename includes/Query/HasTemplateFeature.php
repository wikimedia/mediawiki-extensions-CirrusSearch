<?php

namespace CirrusSearch\Query;

use CirrusSearch\Search\SearchContext;
use Title;

/**
 * We emulate template syntax here as best as possible, so things in NS_MAIN
 * are prefixed with ":" and things in NS_TEMPATE don't have a prefix at all.
 * Since we don't actually index templates like that, munge the query here.
 */
class HasTemplateFeature extends SimpleKeywordFeature {
	/**
	 * @return string[]
	 */
	protected function getKeywords() {
		return [ 'hastemplate' ];
	}

	/**
	 * @param SearchContext $context
	 * @param string $key The keyword
	 * @param string $value The value attached to the keyword with quotes stripped
	 * @param string $quotedValue The original value in the search string, including quotes if used
	 * @param bool $negated Is the search negated? Not used to generate the returned AbstractQuery,
	 *  that will be negated as necessary. Used for any other building/context necessary.
	 * @return array Two element array, first an AbstractQuery or null to apply to the
	 *  query. Second a boolean indicating if the quotedValue should be kept in the search
	 *  string.
	 */
	protected function doApply( SearchContext $context, $key, $value, $quotedValue, $negated ) {
		if ( strpos( $value, ':' ) === 0 ) {
			$value = substr( $value, 1 );
		} else {
			$title = Title::newFromText( $value );
			if ( $title && $title->getNamespace() === NS_MAIN ) {
				$value = Title::makeTitle( NS_TEMPLATE, $title->getDBkey() )
					->getPrefixedText();
			}
		}
		return [ QueryHelper::matchPage( 'template', $value ), false ];
	}
}
