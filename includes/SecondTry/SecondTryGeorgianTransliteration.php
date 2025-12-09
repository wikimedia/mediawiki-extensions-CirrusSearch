<?php

namespace CirrusSearch\SecondTry;

/**
 * Georgian transliteration.
 *
 * @license GPL-2.0-or-later
 */

/**
 * See {@link https://www.mediawiki.org/wiki/User:TJones_(WMF)/Notes/N.O.R.M._MVP_Design_Notes/Georgian_Transliteration}
 * @author Trey Jones
 */
class SecondTryGeorgianTransliteration implements SecondTrySearch {

	/* Georgian Transliteration Tables */
	/** @var array */
	private array $latToGeoMap = [
		// case variants of digraphs/trigraph
		'TCH' => 'ჭ', 'Tch' => 'ჭ', 'tch' => 'ჭ',
		'ZH' => 'ჟ', 'Zh' => 'ჟ', 'zh' => 'ჟ',
		'GH' => 'ღ', 'Gh' => 'ღ', 'gh' => 'ღ',
		'SH' => 'შ', 'Sh' => 'შ', 'sh' => 'შ',
		'CH' => 'ჩ', 'Ch' => 'ჩ', 'ch' => 'ჩ',
		'DZ' => 'ძ', 'Dz' => 'ძ', 'dz' => 'ძ',
		'TS' => 'ც', 'Ts' => 'ც', 'ts' => 'ც',
		'KH' => 'ხ', 'Kh' => 'ხ', 'kh' => 'ხ',
		// single-letter mixed-case transliteration
		'a' => 'ა', 'b' => 'ბ', 'g' => 'გ', 'd' => 'დ', 'e' => 'ე', 'v' => 'ვ',
		'z' => 'ზ', 'T' => 'თ', 'i' => 'ი', 'k' => 'კ', 'l' => 'ლ', 'm' => 'მ',
		'n' => 'ნ', 'o' => 'ო', 'p' => 'პ', 'J' => 'ჟ', 'r' => 'რ', 's' => 'ს',
		't' => 'ტ', 'u' => 'უ', 'f' => 'ფ', 'q' => 'ქ', 'R' => 'ღ', 'y' => 'ყ',
		'S' => 'შ', 'C' => 'ჩ', 'c' => 'ც', 'Z' => 'ძ', 'w' => 'წ', 'W' => 'ჭ',
		'x' => 'ხ', 'j' => 'ჯ', 'h' => 'ჰ'
	];

	/** @var array */
	private array $latToGeoLcOnlyMap = [
		// single letters that have no uppercase mapping
		'a' => 'ა', 'b' => 'ბ', 'g' => 'გ', 'd' => 'დ', 'e' => 'ე', 'v' => 'ვ',
		'i' => 'ი', 'k' => 'კ', 'l' => 'ლ', 'm' => 'მ', 'n' => 'ნ', 'o' => 'ო',
		'p' => 'პ', 'u' => 'უ', 'f' => 'ფ', 'q' => 'ქ', 'y' => 'ყ', 'x' => 'ხ',
		'h' => 'ჰ',
	];

	/** @var array */
	private array $cyrToGeoMap = [
		// delete combining  ̀  ́  ̂  ̄
		"\u{0300}" => '', "\u{0301}" => '', "\u{0302}" => '', "\u{0304}" => '',
		// Cyr-to-Geo and Geo-to-Cyr digraphs
		'дж' => 'ჯ', 'дз' => 'ძ', 'я' => 'ია',
		// one-to-one mappings
		'а' => 'ა', 'б' => 'ბ', 'д' => 'დ', 'е' => 'ე', 'э' => 'ე', 'в' => 'ვ',
		'з' => 'ზ', 'и' => 'ი', 'л' => 'ლ', 'м' => 'მ', 'н' => 'ნ', 'о' => 'ო',
		'ж' => 'ჟ', 'р' => 'რ', 'с' => 'ს', 'у' => 'უ', 'ш' => 'შ', 'г' => 'გ',
		'к' => 'კ', 'х' => 'ხ', 'ц' => 'ც', 'ч' => 'ჩ', 'і' => 'ი', 'є' => 'ე',
		'ґ' => 'გ',
	];

	/** @var array */
	private array $cyrToGeoAmbigMap = [
		// т and п are extra ambiguous, play the odds
		// most specific
		'იтა' => 'იტა', 'ეтრ' => 'ეტრ', 'აтი' => 'ატი', 'სтა' => 'სტა',
		'ნтა' => 'ნტა', 'აтუ' => 'ატუ', 'აтრ' => 'ატრ', 'ხтა' => 'ხტა',

		'მпი' => 'მპი', 'სпა' => 'სპა', 'აпო' => 'აპო', 'ოпო' => 'ოპო',
		'სпი' => 'სპი', 'სпო' => 'სპო', 'მпე' => 'მპე', 'იпე' => 'იპე',
		'ეпა' => 'ეპა',

		// less specific
		'ეт' => 'ეთ', 'тო' => 'ტო', 'რт' => 'რთ', 'აт' => 'ათ',
		'უт' => 'უტ', 'тლ' => 'თლ', 'тვ' => 'თვ', 'тა' => 'თა',

		'пუ' => 'პუ', 'აп' => 'აფ', 'ოп' => 'ოფ',
	];

	/** @var array */
	private array $cyrToGeoAmbigRegexFrom = [
		// word boundary regexes
		'/\\bтრ/u', '/\\bтი/u', '/\\bпა/u', '/\\bпო/u', '/\\bпრ/u',
		'/\\bт/u', '/т\\b/u', '/\\bп/u', '/п\\b/u',
		// defaults (these aren't regexes, but we can pick them up here)
		'/т/u', '/п/u'
	];
	/** @var array */
	private array $cyrToGeoAmbigRegexTo = [
		// word boundary regexes
		'ტრ', 'ტი', 'პა', 'პო', 'პრ',
		'თ', 'თ', 'ფ', 'პ',
		// defaults
		'ტ', 'ფ'
	];

	/**
	 * Map Latin/Cyrillic -> Georgian.
	 *
	 * {@inheritDoc}
	 */
	public function candidates( string $searchQuery ): array {
		if ( preg_match( '/\p{Georgian}/u', $searchQuery ) ) {
			// if Georgian... they don't need our help with transliteration
			return [];
		}
		$out = $searchQuery;
		$changed = false;
		if ( preg_match( '/[A-Za-z]/', $searchQuery ) ) {
			// if Latin... convert digraphs & mixed-case single letters
			$out = strtr( $out, $this->latToGeoMap );
			// lowercase the remainder
			$out = mb_strtolower( $out );
			// map lowercase-only single letters
			$out = strtr( $out, $this->latToGeoLcOnlyMap );
			// if there is any leftover Latin, it's a failure!
			if ( preg_match( '/\p{Latin}/u', $out ) ) {
				return [];
			}
			$changed = true;
		}
		if ( preg_match( '/[А-ИК-УХ-ШЭЯа-ик-ух-шэяЄєІіҐґ]/u', $searchQuery ) ) {
			// If Cyrillic... lowercase, because there are no Cyrillic case distinctions
			$out = mb_strtolower( $out );
			// remove diacritics, map digraphs, single letters that create context for
			// later, longer patterns
			$out = strtr( $out, $this->cyrToGeoMap );
			// non-regex ambiguous contexts
			$out = strtr( $out, $this->cyrToGeoAmbigMap );
			// map regex ambiguous contexts, and ambiguous defaults
			$out = preg_replace( $this->cyrToGeoAmbigRegexFrom,
				$this->cyrToGeoAmbigRegexTo, $out );
			// if there is any leftover Cyrillic, it's a failure!
			if ( preg_match( '/\p{Cyrillic}/u', $out ) ) {
				return [];
			}
			$changed = true;
		}
		if ( $changed ) {
			return [ $out ];
		}
		return [];
	}
}
