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
			return preg_replace( '/(\S+)"(\S)/', '\1\\"\2', $text );
		}
		return $text;
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
