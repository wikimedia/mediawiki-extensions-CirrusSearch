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
	 * Whether this keyword is greedy consuming the rest of the string.
	 * NOTE: do not override, greedy keywords will eventually be removed in the future
	 * @return bool
	 */
	public function greedy() {
		return false;
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
	 * Determine the name of the feature being set in SearchContext::addSyntaxUsed
	 * Defaults to $key
	 *
	 * @param string $key
	 * @param string $valueDelimiter the delimiter used to wrap the value
	 * @return string
	 *  '"' when parsing keyword:"test"
	 *  '' when parsing keyword:test
	 */
	public function getFeatureName( $key, $valueDelimiter ) {
		return $key;
	}

	/**
	 * Captures either a quoted or unquoted string. Quoted strings may have
	 * escaped (\") quotes embedded in them.
	 *
	 * @return string A piece of a regular expression (not wrapped in //) that
	 * matches the acceptable values for this feature. Must contain quoted and
	 * unquoted capture groups.
	 */
	private function getValueRegex() {
		assert( $this->hasValue(), __METHOD__ . ' called but hasValue() is false' );
		if ( $this->greedy() ) {
			assert( !$this->allowEmptyValue(), "greedy keywords must not accept empty value" );
			// XXX: we send raw value to the keyword
			return '(?<unquoted>.*)';
		} else {
			$quantifier = $this->allowEmptyValue() ? '*' : '+';
			return '"(?<quoted>(?:\\\\"|[^"])*)"|(?<unquoted>[^"\s]' . $quantifier . ')';
		}
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
				$valueDelimiter = '';
				if ( $this->hasValue() ) {
					$quotedValue = $match['value'];
					if ( isset( $match['unquoted'] ) ) {
						$value = $match['unquoted'];
						$valueDelimiter = '"';
					} else {
						$value = str_replace( '\"', '"', $match['quoted'] );
					}
				}
				if ( $key[0] === '-' ) {
					$negated = true;
					$key = substr( $key, 1 );
				} else {
					$negated = false;
				}

				$context->addSyntaxUsed( $this->getFeatureName( $key, $valueDelimiter ) );
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
