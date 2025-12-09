<?php

namespace CirrusSearch\SecondTry;

/**
 * Hebrew keyboard transformation.
 *
 * @license GPL-2.0-or-later
 */

/**
 * See {@link https://www.mediawiki.org/wiki/User:TJones_(WMF)/Notes/N.O.R.M._MVP_Design_Notes/Russian_and_Hebrew_DWIM}
 * @author Trey Jones
 */
class SecondTryHebrewKeyboard implements SecondTrySearch {
	use SecondTrySearchTrait;

	/** If you have more than 25 words in your query, second-try search won't save you */
	private const MAX_WORD_SPLIT = 25;

	/** Wrong Keyboard Map Indexing constants */
	private const QWERTY_TO_HE = 0;
	private const HE_TO_QWERTY = 1;
	private const MAP_DIR_BOTH = 2;

	/** Allowed mapping direction names to values */
	private const DIR_NAMES = [
		'q2h' => self::QWERTY_TO_HE,
		'l2h' => self::QWERTY_TO_HE,
		'h2q' => self::HE_TO_QWERTY,
		'h2l' => self::HE_TO_QWERTY,
		'both' => self::MAP_DIR_BOTH
	];

	/** @var array<int, array<string, string>> */
	private array $heQwertyMaps;

	/**
	 * @param array $config build options
	 *        dir (string): mapping direction ('q2h'/'l2h': Latin Qwerty->Hebrew;
	 *             'h2q'/'h2l': Hebrew->Latin Qwerty; 'both': both directions)
	 */
	public static function build( array $config ): SecondTryHebrewKeyboard {
		return new self( self::DIR_NAMES[ $config['dir'] ?? 'both' ] );
	}

	/**
	 * @param int $dir mapping direction (0:QWERTY_TO_HE; 1:HE_TO_QWERTY; 2:both)
	 */
	public function __construct( int $dir = self::MAP_DIR_BOTH ) {
		// set up He PC <-> QWERTY mappings
		$this->heQwertyMaps = $this->stringToWrongKeyboardMaps(
			// the Hebrew string can display unexpectedly; edit with care.
			// map upper and lower QWERTY to the same Hebrew letter to avoid needing
			// to lowercase while mapping. Map upper first so lowercase is preferred
			// HE->QWERTY mapping.
			"EeRrTtYyUuIiOoPpAaSsDdFfGgHhJjKkLlZzXxCcVvBbNnMmWw',Qq/.;",
			"קקרראאטטווןןםםפפששדדגגככעעייחחללךךזזססבבההננממצצ'',ת/./.ץף",
			$dir
		);
	}

	/**
	 * Map Hebrew PC <-> Latin qwerty, word by word.
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
			$trMap = preg_match( '/[א-ת]/', $w ) ?
				$this->heQwertyMaps[self::HE_TO_QWERTY] :
				$this->heQwertyMaps[self::QWERTY_TO_HE];
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
