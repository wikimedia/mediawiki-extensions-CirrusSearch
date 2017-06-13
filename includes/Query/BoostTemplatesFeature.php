<?php

namespace CirrusSearch\Query;

use CirrusSearch\Search\SearchContext;

/**
 * Handles the boost-templates keyword in full text search. Allows user
 * to specify a percentage to increase or decrease a search result by based
 * on the templates included in the page. Templates can be specified with
 * spaces or underscores. Multiple templates can be specified. Any value
 * including a space must be quoted.
 *
 * Examples:
 *  boost-templates:Main_article|250%
 *  boost-templates:"Featured sound|150%"
 *  boost-templates:"Main_article|250% List_of_lists|10%"
 */
class BoostTemplatesFeature extends SimpleKeywordFeature {
	/**
	 * @return string[]
	 */
	protected function getKeywords() {
		return [ 'boost-templates' ];
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
		$context->setBoostTemplatesFromQuery(
			self::parseBoostTemplates( $value )
		);

		return [ null, false ];
	}

	/**
	 * Parse boosted templates.  Parse failures silently return no boosted templates.
	 * Matches a template name followed by a | then a positive integer followed by a %.
	 * Multiple templates can be specified separated by a space.
	 *
	 * Examples:
	 *   Featured_article|150%
	 *   List of lists|10% Featured_sound|200%
	 *
	 * @param string $text text representation of boosted templates
	 * @return float[] map of boosted templates (key is the template, value is a float).
	 */
	public static function parseBoostTemplates( $text ) {
		$boostTemplates = [];
		$templateMatches = [];
		if ( preg_match_all( '/([^|]+)\|(\d+)% ?/', $text, $templateMatches, PREG_SET_ORDER ) ) {
			foreach ( $templateMatches as $templateMatch ) {
				// templates field is populated with Title::getPrefixedText
				// which will replace _ to ' '. We should do the same here.
				$template = strtr( $templateMatch[ 1 ], '_', ' ' );
				$boostTemplates[ $template ] = floatval( $templateMatch[ 2 ] ) / 100;
			}
		}
		return $boostTemplates;
	}
}
