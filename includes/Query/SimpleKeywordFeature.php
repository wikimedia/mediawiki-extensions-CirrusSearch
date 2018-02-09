<?php

namespace CirrusSearch\Query;

use CirrusSearch\Search\SearchContext;

/**
 * Implements abstract handling of keyword features that are composed of a
 * keyword followed by a colon then an optionally quoted value. For consistency
 * most query features should be implemented this way using the default
 * getValueRegex() where possible.
 */
abstract class SimpleKeywordFeature implements KeywordFeature {
	/**
	 * @return string[] The list of keywords this feature is supposed to match
	 */
	abstract protected function getKeywords();

	/**
	 * Whether this keyword allows empty value.
	 * @return bool true to allow the keyword to appear in an empty form
	 */
	public function allowEmptyValue() {
		return false;
	}

	/**
	 * Whether this keyword can have a value
	 * @return bool
	 */
	public function hasValue() {
		return true;
	}

	/**
	 * Whether this keyword can appear only at the beginning of the query
	 * (excluding spaces)
	 * @return bool
	 */
	public function queryHeader() {
		return false;
	}

	/**
	 * Captures either a quoted or unquoted string. Quoted strings may have
	 * escaped (\") quotes embedded in them.
	 *
	 * @return string A piece of a regular expression (not wrapped in //) that
	 * matches the acceptable values for this feature. Must contain quoted and
	 * unquoted capture groups.
	 */
	protected function getValueRegex() {
		assert( $this->hasValue(), __METHOD__ . ' called but hasValue() is false' );
		$quantifier = $this->allowEmptyValue() ? '*' : '+';
		return '"(?<quoted>(?:\\\\"|[^"])*)"|(?<unquoted>[^"\s]' . $quantifier . ')';
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
		$keyListRegex = implode(
			'|',
			array_map(
				function ( $kw ) {
					return preg_quote( $kw );
				},
				$this->getKeywords()
			)
		);
		// Hook to the beginning allowing optional spaces if we are a queryHeader
		// otherwise lookbehind allowing begin or space.
		$begin = $this->queryHeader() ? '(?:^\s*)' : '(?<=^|\s)';
		$keywordRegex = '(?<key>-?' . $keyListRegex . ')';
		$valueSideRegex = '';
		if ( $this->hasValue() ) {
			$valueRegex = '(?<value>' . $this->getValueRegex() . ')';
			// If we allow empty values we don't allow spaces between
			// the keyword and its value, a space would mean "empty value"
			$spacesAfterSep = $this->allowEmptyValue() ? '' : '\s*';
			$valueSideRegex = "${spacesAfterSep}{$valueRegex}\\s?";
		}

		return QueryHelper::extractSpecialSyntaxFromTerm(
			$context,
			$term,
			// initial positive lookbehind ensures keyword doesn't
			// match in the middle of a word.
			"/{$begin}{$keywordRegex}:${valueSideRegex}/",
			function ( $match ) use ( $context ) {
				$key = $match['key'];
				assert( $this->hasValue() === isset( $match['value'] ) );
				$quotedValue = '';
				$value = '';
				if ( $this->hasValue() ) {
					$quotedValue = $match['value'];
					$value =
						isset( $match['unquoted'] )
							? $match['unquoted'] : str_replace( '\"', '"', $match['quoted'] );
				}
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
				// FIXME: this adds a trailing space if this is the last keyword
				return $keepText ? "$quotedValue " : '';
			}
		);
	}
}
