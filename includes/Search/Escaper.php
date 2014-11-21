<?php

namespace CirrusSearch\Search;

use \ProfileSection;

/**
 * Escapes queries.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */
class Escaper {
	private $language;

	public function __construct( $language ) {
		$this->language = $language;
	}

	public function escapeQuotes( $text ) {
		$profiler = new ProfileSection( __METHOD__ );
		if ( $this->language === 'he' ) {
			// Hebrew uses the double quote (") character as a standin for quotation marks (“”)
			// which delineate phrases.  It also uses double quotes as a standin for another
			// character (״), call a Gershayim, which mark acronyms.  Here we guess if the intent
			// was to mark a phrase, in which case we leave the quotes alone, or to mark an
			// acronym, in which case we escape them.
			return preg_replace( '/(\S+)"(\S)/u', '\1\\"\2', $text );
		}
		return $text;
	}

	/**
	 * Make sure the the query string part is well formed by escaping some syntax that we don't
	 * want users to get direct access to and making sure quotes are balanced.
	 * These special characters _aren't_ escaped:
	 * * and ?: Do a wildcard search against the stemmed text which isn't strictly a good
	 * idea but this is so rarely used that adding extra code to flip prefix searches into
	 * real prefix searches isn't really worth it.
	 * ~: Do a fuzzy match against the stemmed text which isn't strictly a good idea but it
	 * gets the job done and fuzzy matches are a really rarely used feature to be creating an
	 * extra index for.
	 * ": Perform a phrase search for the quoted term.  If the "s aren't balanced we insert one
	 * at the end of the term to make sure elasticsearch doesn't barf at us.
	 */
	public function fixupQueryStringPart( $string ) {
		$profiler = new ProfileSection( __METHOD__ );

		// Escape characters that can be escaped with \\
		$string = preg_replace( '/(
				\(|     (?# no user supplied groupings)
				\)|
				\{|     (?# no exclusive range queries)
				}|
				\[|     (?# no inclusive range queries either)
				]|
				\^|     (?# no user supplied boosts at this point, though I cant think why)
				:|		(?# no specifying your own fields)
				\\\(?!") (?# the only acceptable escaping is for quotes)
			)/x', '\\\$1', $string );
		// Forward slash escaping doesn't work properly in all environments so we just eat them.   Nom.
		$string = str_replace( '/', ' ', $string );

		// Elasticsearch's query strings can't abide unbalanced quotes
		return $this->balanceQuotes( $string );
	}

	/**
	 * Make sure that all operators and lucene syntax is used correctly in the query string
	 * and store if this is a fuzzy query.
	 * If it isn't then the syntax escaped so it becomes part of the query text.
	 * @return array(string, boolean) (fixedup query string, is this a fuzzy query?)
	 */
	public function fixupWholeQueryString( $string ) {
		$profiler = new ProfileSection( __METHOD__ );

		// Be careful when editing this method because the ordering of the replacements matters.

		// Escape ~ that don't follow a term or a quote
		$string = preg_replace_callback( '/(?<![\w"])~/u',
			'CirrusSearch\Search\Escaper::escapeBadSyntax', $string );

		// Remove ? and * that don't follow a term.  These are slow so we turned them off and escaping isn't working....
		$string = preg_replace( '/(?<![\w])([?*])/u', '', $string );

		// Reduce token ranges to bare tokens without the < or >
		$string = preg_replace( '/(?:<|>)+([^\s])/u', '$1', $string );

		// Turn bad fuzzy searches into searches that contain a ~ and set $this->fuzzyQuery for good ones.
		$fuzzyQuery = false;
		$string = preg_replace_callback( '/(?<leading>\w)~(?<trailing>\S*)/u',
			function ( $matches ) use ( &$fuzzyQuery ) {
				if ( preg_match( '/^(?:|0|(?:0?\.[0-9]+)|(?:1(?:\.0)?))$/', $matches[ 'trailing' ] ) ) {
					$fuzzyQuery = true;
					return $matches[ 0 ];
				} else {
					return $matches[ 'leading' ] . '\\~' .
						preg_replace( '/(?<!\\\\)~/', '\~', $matches[ 'trailing' ] );
				}
			}, $string );

		// Turn bad proximity searches into searches that contain a ~
		$string = preg_replace_callback( '/"~(?<trailing>\S*)/u', function ( $matches ) {
			if ( preg_match( '/[0-9]+/', $matches[ 'trailing' ] ) ) {
				return $matches[ 0 ];
			} else {
				return '"\\~' . $matches[ 'trailing' ];
			}
		}, $string );

		// Escape +, -, and ! when not immediately followed by a term or when immediately
		// prefixed with a term.  Catches "foo-bar", "foo- bar", "foo - bar".  The only
		// acceptable use is "foo -bar" and "-bar foo".
		$string = preg_replace_callback( '/[+\-!]+(?!\w)/u',
			'CirrusSearch\Search\Escaper::escapeBadSyntax', $string );
		$string = preg_replace_callback( '/(?<!^|[ \\\\])[+\-!]+/u',
			'CirrusSearch\Search\Escaper::escapeBadSyntax', $string );

		// Escape || when not between terms
		$string = preg_replace_callback( '/^\s*\|\|/u',
			'CirrusSearch\Search\Escaper::escapeBadSyntax', $string );
		$string = preg_replace_callback( '/\|\|\s*$/u',
			'CirrusSearch\Search\Escaper::escapeBadSyntax', $string );

		// Lowercase AND and OR when not surrounded on both sides by a term.
		// Lowercase NOT when it doesn't have a term after it.
		$string = preg_replace_callback( '/^\s*(?:AND|OR)/u',
			'CirrusSearch\Search\Escaper::lowercaseMatched', $string );
		$string = preg_replace_callback( '/(?:AND|OR|NOT)\s*$/u',
			'CirrusSearch\Search\Escaper::lowercaseMatched', $string );

		return array( $string, $fuzzyQuery );
	}

	private static function escapeBadSyntax( $matches ) {
		return "\\" . implode( "\\", str_split( $matches[ 0 ] ) );
	}

	private static function lowercaseMatched( $matches ) {
		return strtolower( $matches[ 0 ] );
	}

	public function balanceQuotes( $text ) {
		$profiler = new ProfileSection( __METHOD__ );
		$inQuote = false;
		$inEscape = false;
		$len = strlen( $text );
		for ( $i = 0; $i < $len; $i++ ) {
			if ( $inEscape ) {
				$inEscape = false;
				continue;
			}
			switch ( $text[ $i ] ) {
			case '"':
				$inQuote = !$inQuote;
				break;
			case '\\':
				$inEscape = true;
			}
		}
		if ( $inQuote ) {
			$text = $text . '"';
		}
		return $text;
	}
}
