<?php

namespace CirrusSearch\SecondTry;

/**
 * Russian keyboard transformation.
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
class SecondTryRussianKeyboard implements SecondTrySearch {
	use SecondTrySearchTrait;

	/** If you have more than 25 words in your query, second-try search won't save you */
	private const MAX_WORD_SPLIT = 25;

	/** Wrong Keyboard Map Indexing constants */
	private const QWERTY_TO_RU = 0;
	private const RU_TO_QWERTY = 1;
	private const MAP_DIR_BOTH = 2;

	/** Allowed mapping direction names to values */
	private const DIR_NAMES = [
		'q2r' => self::QWERTY_TO_RU,
		'l2c' => self::QWERTY_TO_RU,
		'r2q' => self::RU_TO_QWERTY,
		'c2l' => self::RU_TO_QWERTY,
		'both' => self::MAP_DIR_BOTH
	];

	/** @var array<int, array<string, string>> */
	private array $ruQwertyMaps;

	/**
	 * @param array $config build options
	 *        dir (string): mapping direction ('q2r'/'l2c': Latin Qwerty->Russian Cyrillic;
	 *             'r2q'/'c2l': Russian Cyrillic->Latin Qwerty; 'both': both directions)
	 */
	public static function build( array $config ): SecondTryRussianKeyboard {
		return new self( self::DIR_NAMES[ $config['dir'] ?? 'both' ] );
	}

	/**
	 * @param int $dir mapping direction (0:QWERTY_TO_RU; 1:RU_TO_QWERTY; 2:both)
	 */
	public function __construct( int $dir = self::MAP_DIR_BOTH ) {
		// set up Ru PC <-> QWERTY mappings
		$this->ruQwertyMaps = self::stringToWrongKeyboardMaps(
			'QqWwEeRrTtYyUuIiOoPpAaSsDdFfGgHhJjKkLlZzXxCcVvBbNnMm{[}]~`:^;$<,?&>./|#"\'',
			'ЙйЦцУуКкЕеНнГгШшЩщЗзФфЫыВвАаПпРрОоЛлДдЯяЧчСсМмИиТтЬьХхЪъЁёЖ:ж;Бб,?Юю./№Ээ',
			$dir
		);
	}

	/**
	 * Map Ru PC Cyrillic <-> Latin qwerty, word by word.
	 * {@inheritDoc}
	 */
	public function candidates( string $searchQuery ): array {
		// Split words so we have the possibility of remapping a mixed-script string;
		// Only trust spaces as word delimiters, since punctuation on one keyboard
		// can be letters on another.
		$words = explode( ' ', $searchQuery, self::MAX_WORD_SPLIT );
		$changed = false;
		foreach ( $words as &$w ) {
			// - If there's any mappable Russian Cyrillic, try mapping to Latin qwerty.
			// - By default map Latin qwerty to Russian Cyrillic.
			// - We can only map in one direction because some characters are ambiguous.
			// For example, in the qwerty-to-Russian direction, a semicolon maps to ж; in
			// the other direction, it maps to a dollar sign.
			$trMap = preg_match( '/[А-Яа-яЁё№]/', $w ) ?
				$this->ruQwertyMaps[self::RU_TO_QWERTY] :
				$this->ruQwertyMaps[self::QWERTY_TO_RU];
			$remapped = strtr( $w, $trMap );
			if ( !$changed ) {
				$changed = $remapped !== $w;
			}
			$w = $remapped;
		}
		if ( $changed ) {
			return [ implode( ' ', $words ) ];
		}
		return [];
	}

}
