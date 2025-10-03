<?php

namespace CirrusSearch\SecondTry;

/**
 * Hebrew keyboard transformation.
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

/**
 * See {@link https://www.mediawiki.org/wiki/User:TJones_(WMF)/Notes/N.O.R.M._MVP_Design_Notes/Russian_and_Hebrew_DWIM}
 * @author Trey Jones
 */
class SecondTryHebrewKeyboard implements SecondTrySearch {
	use SecondTrySearchTrait;

	/** If you have more than 25 words in your query, second-try search won't save you */
	private const MAX_WORD_SPLIT = 25;

	private const QWERTY_TO_HE = 0;
	private const HE_TO_QWERTY = 1;

	/** @var array<int, array<string, string>> */
	private array $heQwertyMaps;

	public function __construct() {
		// set up He PC <-> QWERTY mappings
		$this->heQwertyMaps = $this->stringToWrongKeyboardMaps(
		// the Hebrew string can display unexpectedly; edit with care
			"ertyuiopasdfghjklzxcvbnmw',q/.;", "קראטוןםפשדגכעיחלךזסבהנמצ',ת/.ץף"
		);
	}

	/**
	 * Map Hebrew PC <-> Latin qwerty, word by word
	 * {@inheritDoc}
	 */
	public function candidates( string $searchQuery ): array {
		// Split words so we have the possibility of remapping a mixed-script string;
		// Only trust spaces as word delimiters, since punctuation on one keyboard
		// can be letters on another.
		$words = explode( ' ', $searchQuery, self::MAX_WORD_SPLIT );
		$changed = false;
		foreach ( $words as &$w ) {
			// - If there's any mappable Hebrew, try mapping to Latin qwerty.
			// - Mixed Hebrew and upper Latin should map the Hebrew to qwerty.
			// - By default map (lowercased) Latin qwerty to Hebrew.
			// - We can only map in one direction because some characters are ambiguous.
			// For example, in the qwerty-to-Hebrew direction, a comma maps to ת; in
			// the other direction, it maps to an apostrophe.
			if ( preg_match( '/[א-ת]/', $w ) ) {
				$remapped = strtr( $w, $this->heQwertyMaps[self::HE_TO_QWERTY] );
				if ( !$changed ) {
					$changed = $remapped !== $w;
				}
				$w = $remapped;
			} else {
				$lc = strtolower( $w );
				$w = strtr( $lc, $this->heQwertyMaps[self::QWERTY_TO_HE] );
				if ( !$changed ) {
					$changed = $lc !== $w;
				}
			}
		}
		if ( $changed ) {
			return [ implode( ' ', $words ) ];
		}
		return [];
	}
}
