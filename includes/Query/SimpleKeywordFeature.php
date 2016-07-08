<?php

namespace CirrusSearch\Query;

use CirrusSearch\Searcher;
use CirrusSearch\Search\SearchContext;

/**
 * Implements abstract handling of keyword features that are composed of a
 * keyword followed by a colon then an optionally quoted value. For consistency
 * most query features should be implemented this way using the default
 * getValueRegex() where possible.
 */
abstract class SimpleKeywordFeature implements KeywordFeature {
	/**
	 * @return string A piece of a regular expression (not wrapped in //) that
	 * matches the key to trigger this feature. Does not include the negation
	 * (-) prefix.
	 */
	abstract protected function getKeywordRegex();

	/**
	 * Captures either a quoted or unquoted string. Quoted strings may have
	 * escaped (\") quotes embedded in them.
	 *
	 * @return string A piece of a regular expression (not wrapped in //) that
	 * matches the acceptable values for this feature. Must contain quoted and
	 * unquoted capture groups.
	 */
	protected function getValueRegex() {
		return '"(?<quoted>(?:\\\\"|[^"])*)"|(?<unquoted>[^"\s]+)';
	}

	/**
	 * Applies the detected keyword from the search term. May apply changes
	 * either to $context directly, or return a filter to be added.
	 *
	 * @param SearchContext $context
	 * @param string $key The keyword
	 * @param string $value The value attached to the keyword with quotes stripped and escaped
	 *  quotes un-escaped.
	 * @param string $quotedValue The original value in the search string, including quotes if used
	 * @param bool $negated Is the search negated? Not used to generate the returned AbstractQuery,
	 *  that will be negated as necessary. Used for any other building/context necessary.
	 * @return array Two element array, first an AbstractQuery or null to apply to the
	 *  query. Second a boolean indicating if the quotedValue should be kept in the search
	 *  string.
	 */
	abstract protected function doApply( SearchContext $context, $key, $value, $quotedValue, $negated );

	/**
	 * @param SearchContext $context
	 * @param string $term Search query
	 * @return string Remaining search query
	 */
	public function apply( SearchContext $context, $term ) {
		$keywordRegex = '(?<key>-?' . $this->getKeywordRegex() . ')';
		$valueRegex = '(?<value>' . $this->getValueRegex() . ')';

		return QueryHelper::extractSpecialSyntaxFromTerm(
			$context,
			$term,
			// initial positive lookbehind ensures keyword doesn't
			// match in the middle of a word.
			"/(?<=^|\\s){$keywordRegex}:\\s*{$valueRegex}\\s?/",
			function ( $match ) use ( $context ) {
				$key = $match['key'];
				$quotedValue = $match['value'];
				$value = isset( $match['unquoted'] )
					? $match['unquoted']
					: str_replace( '\"', '"', $match['quoted']);

				if ( $key[0] === '-' ) {
					$negated = true;
					$key = substr( $key, 1 );
				} else {
					$negated = false;
				}

				$context->addSyntaxUsed( $key );
				list( $filter, $keepText ) = $this->doApply(
					$context,
					$key,
					$value,
					$quotedValue,
					$negated
				);
				if ( $filter !== null ) {
					if ( $negated ) {
						$context->addNotFilter( $filter );
					} else {
						$context->addFilter( $filter );
					}
				}

				return $keepText ? "$quotedValue " : '';
			}
		);
	}
}
