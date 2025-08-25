<?php

namespace CirrusSearch\SecondTry;

/**
 * Various methods to transform query strings for second-try searching.
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
class SecondTrySearch {
	/** Wrong Keyboard Map Indexing constants */
	private const QWERTY_TO_RU = 0;
	private const RU_TO_QWERTY = 1;

	private const QWERTY_TO_HE = 0;
	private const HE_TO_QWERTY = 1;

	/** If you have more than 25 words in your query, second-try search won't save you */
	private const MAX_WORD_SPLIT = 25;

	/** @var array<int, array<string, string>> */
	private $ruQwertyMaps;

	/** @var array<int, array<string, string>> */
	private $heQwertyMaps;

	public function __construct() {
		// set up Ru PC <-> QWERTY mappings
		$this->ruQwertyMaps = $this->stringToWrongKeyboardMaps(
			'QqWwEeRrTtYyUuIiOoPpAaSsDdFfGgHhJjKkLlZzXxCcVvBbNnMm{[}]~`:^;$<,?&>./|#"\'',
			'ЙйЦцУуКкЕеНнГгШшЩщЗзФфЫыВвАаПпРрОоЛлДдЯяЧчСсМмИиТтЬьХхЪъЁёЖ:ж;Бб,?Юю./№Ээ'
			);

		// set up He PC <-> QWERTY mappings
		$this->heQwertyMaps = $this->stringToWrongKeyboardMaps(
			// the Hebrew string can display unexpectedly; edit with care
			"ertyuiopasdfghjklzxcvbnmw',q/.;", "קראטוןםפשדגכעיחלךזסבהנמצ',ת/.ץף"
			);
	}

	/**
	 * convert easier-to-read mapping strings into useful data structures
	 *
	 * @param string $scr1 chars from keyboard #1
	 * @param string $scr2 corresponding chars from keyboard #2
	 * @return array<int, array<string, string>>
	 */
	private static function stringToWrongKeyboardMaps( string $scr1, string $scr2 ): array {
		$dwim = [];
		for ( $i = 0; $i < mb_strlen( $scr1 ); $i++ ) {
			$c1 = mb_substr( $scr1, $i, 1 );
			$c2 = mb_substr( $scr2, $i, 1 );
			$dwim[0][$c1] = $c2;
			$dwim[1][$c2] = $c1;
		}
		return $dwim;
	}

	/**
	 * Map Ru PC Cyrillic <-> Latin qwerty, word by word
	 *
	 * @param string $string
	 * @return string remapped
	 */
	public function russianWrongKeyboard( string $string ): string {
		// Split words so we have the possibility of remapping a mixed-script string;
		// Only trust spaces as word delimiters, since punctuation on one keyboard
		// can be letters on another.
		$words = explode( ' ', $string, self::MAX_WORD_SPLIT );
		foreach ( $words as &$w ) {
			// - If there's any mappable Russian Cyrillic, try mapping to Latin qwerty.
			// - By default map Latin qwerty to Russian Cyrillic.
			// - We can only map in one direction because some characters are ambiguous.
			// For example, in the qwerty-to-Russian direction, a semicolon maps to ж; in
			// the other direction, it maps to a dollar sign.
			$w = preg_match( '/[А-Яа-яЁё№]/', $w ) ?
				strtr( $w, $this->ruQwertyMaps[self::RU_TO_QWERTY] ) :
				strtr( $w, $this->ruQwertyMaps[self::QWERTY_TO_RU] );
		}
		return implode( ' ', $words );
	}

	/**
	 * Map Hebrew PC <-> Latin qwerty, word by word
	 *
	 * @param string $string
	 * @return string remapped
	 */
	public function hebrewWrongKeyboard( string $string ): string {
		// Split words so we have the possibility of remapping a mixed-script string;
		// Only trust spaces as word delimiters, since punctuation on one keyboard
		// can be letters on another.
		$words = explode( ' ', $string, self::MAX_WORD_SPLIT );
		foreach ( $words as &$w ) {
			// - If there's any mappable Hebrew, try mapping to Latin qwerty.
			// - Mixed Hebrew and upper Latin should map the Hebrew to qwerty.
			// - By default map (lowercased) Latin qwerty to Hebrew.
			// - We can only map in one direction because some characters are ambiguous.
			// For example, in the qwerty-to-Hebrew direction, a comma maps to ת; in
			// the other direction, it maps to an apostrophe.
			$w = preg_match( '/[א-ת]/', $w ) ?
				strtr( $w, $this->heQwertyMaps[self::HE_TO_QWERTY] ) :
				strtr( strtolower( $w ), $this->heQwertyMaps[self::QWERTY_TO_HE] );
		}
		return implode( ' ', $words );
	}

}
