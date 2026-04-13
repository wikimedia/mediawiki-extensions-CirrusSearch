<?php

namespace CirrusSearch\SecondTry;

/**
 * Hindi transliteration.
 *
 * @license GPL-2.0-or-later
 */

/**
 * See {@link https://www.mediawiki.org/wiki/User:TJones_(WMF)/Notes/N.O.R.M._MVP_Design_Notes/Hindi_Transliteration}
 * @author Trey Jones
 */
class SecondTryHindiTransliteration implements SecondTrySearch {

	// If you have more than ~20 Latin words (and 20 non-Latin chunks in between) in your
	// query, second-try search won't save you
	private const MAX_WORD_SPLIT = 40;

	/* Hindi Transliteration Tables */

	/** @var array<string,string> */
	private array $latinToHindiWordMap;

	/** @var array<string,string> */
	private array $latinToHindiPhase1Map = [
		// Phase 1: simple subs
		'eigh' => 'ei', 'sion' => 'shan', 'tion' => 'shan', 'ture' => 'char',
		'dge' => 'j', 'jiv' => 'jiiv', 'jiw' => 'jiiv', 'sce' => 'se', 'sci' => 'si',
		'ce' => 'se', 'ci' => 'si', 'ck' => 'k', 'ff' => 'f', 'll' => 'l', 'w' => 'v',
		'aaon' => 'ओं',
	];

	/** @var array<string,string> */
	private array $latinToHindiPhase2RegexMap = [
		// Phase 2: regexes; some simple subs mixed in b/c order matters wrt previous
		// simple subs or current regexes
		'/ss/' => 's', '/nya$/' => 'न्य', '/^x([bcdfghjklmnpqrstvwxz]|$)/' => 'एक्स$1',
		'/^xi/' => 'shi', '/ayei?n$/' => 'ाएं', '/([eo])n$/' => '$1ं',
		'/([aiu])n$/' => '$1न', '/([aeioujlryz])n([bcdfghjklmnpqrstvwxz])/' => '$1ं$2',
		'/tra$/' => 'trA', '/dra$/' => 'drA', '/ddha$/' => 'ddhA',
		'/(.[^aeiou]a)$/' => '$1a', '/A$/' => 'a', '/(.[^aeiou]i)$/' => '$1i',
		'/(.[^aeiou])iy$/' => '$1iiy', '/(.[^aeiou])ir$/' => '$1iir', '/^out/' => 'आउट',
		'/^a?aae/' => 'आए', '/a?aae/' => 'ाए', '/^aaye/' => 'आए', '/aaye/' => 'ाए',
		'/eeyaa/' => 'ीया', '/eyaa/' => 'ेया', '/eeya/' => 'ीय', '/eya/' => 'ेय',
		'/aye/' => 'ाए', '/iye/' => 'ीय', '/ea/' => 'ee', '/oa/' => 'o', '/ei/' => 'ee',
		'/ie/' => 'ee', '/ey/' => 'e', '/([aeiou]|^)aa/' => '$1आ',
		'/([aeiou]|^)ii/' => '$1ई', '/([aeiou]|^)uu/' => '$1ऊ',
		'/([aeiou]|^)ee/' => '$1ई', '/([aeiou]|^)oo/' => '$1ऊ',
		'/([aeiou]|^)ai/' => '$1ई', '/([aeiou]|^)ae/' => '$1ए',
		'/([aeiou]|^)[ao]u/' => '$1औ', '/aa/' => 'ा', '/ii/' => 'ी', '/uu/' => 'ू',
		'/ee/' => 'ी', '/ai/' => 'ै', '/ae/' => 'ै', '/oo/' => 'ू', '/au/' => 'ौ',
		'/ou/' => 'ौ', '/([aeiou]|^)a/' => 'अ', '/([aeiou]|^)i/' => 'इ',
		'/([aeiou]|^)u/' => 'उ', '/([aeiou]|^)e/' => 'ऐ', '/([aeiou]|^)o/' => 'ओ',
		'/^chr/' => 'kr', '/^kn/' => 'n', '/tch$/' => 'च', '/1st$/' => '१स्ट',
		'/2nd$/' => '२ण्ड', '/3rd$/' => '३र्ड', '/(\d)th$/' => '$1थ',
	];

	/** @var array<string,string> */
	private array $latinToHindiPhase3Map = [
		// Phase 3: simple subs
		'cchh' => 'च्छ', 'chch' => 'च्छ', 'kshr' => 'क्षर', 'cch' => 'च्छ', 'chh' => 'छ',
		'ksh' => 'क्ष', 'sch' => 'श्च', 'shr' => 'श्र', 'trh' => 'त्रह', 'ngh' => 'ङह',
		'bh' => 'भ', 'ch' => 'च', 'dh' => 'ध', 'gh' => 'घ', 'gy' => 'ज्ञ', 'jh' => 'झ',
		'kh' => 'ख़', 'ng' => 'ङ', 'ph' => 'फ', 'rh' => 'ढ़', 'sh' => 'श', 'th' => 'थ',
		'tr' => 'त्र', 'zh' => 'झ', 'a' => '', 'b' => 'ब', 'c' => 'क', 'd' => 'द',
		'e' => 'े', 'f' => 'फ', 'g' => 'ग', 'h' => 'ह', 'i' => 'ि', 'j' => 'ज',
		'k' => 'क', 'l' => 'ल', 'm' => 'म', 'n' => 'न', 'o' => 'ो', 'p' => 'प',
		'q' => 'क', 'r' => 'र', 's' => 'स', 't' => 'त', 'u' => 'ु', 'v' => 'व',
		'x' => 'क्स', 'y' => 'य', 'z' => 'ज़', '0' => '०', '1' => '१', '2' => '२',
		'3' => '३', '4' => '४', '5' => '५', '6' => '६', '7' => '७', '8' => '८',
		'9' => '९',
	];

	/** @var array<string,string> */
	private array $latinToHindiPhase4Map = [
		// Phase 4: more simple subs, but they all need to come after Phase 3
		'रुप' => 'रूप', 'तक' => 'टक', 'दक' => 'डक', 'रन' => 'रण', 'षन' => 'षण',
		'ोत' => 'ोट', 'ोद' => 'ोड', 'उथ' => 'उठ', 'गथ' => 'गठ', 'छथ' => 'छठ',
		'ाथ' => 'ाठ', 'ीथ' => 'ीठ', 'ूथ' => 'ूठ', 'ैथ' => 'ैठ', 'ोथ' => 'ोठ', 'अो' => 'औ',
	];

	/** @var array<string,string> */
	private array $latinToHindiPhase5RegexMap = [
		// Phase 5: regexes, plus simple subs imported from Phase 4 to avoid overlaps
		'/पर/' => 'प्र', '/कद/' => 'कड', '/णा$/' => 'ण', '/रया$/' => 'रय',
		'/ीया$/' => 'ीय', '/तवा$/' => 'तव',
	];

	public function __construct() {
		// load direct mappings of common words from file
		$this->latinToHindiWordMap =
			require __DIR__ . '/SecondTryLanguageData/hindiDirectMappings.php';
	}

	/**
	 * Map Latin -> Hindi.
	 *
	 * {@inheritDoc}
	 */
	public function candidates( string $searchQuery ): array {
		if ( !preg_match( '/[A-Za-z]/', $searchQuery ) ) {
			// We do see mixed Latin/Devanagari queries that are still all Hindi, so we
			// don't just bail if Devanagari is present, but we can bail if there is no
			// plain Latin at all to work on.
			return [];
		}

		$searchQuery = mb_strtolower( $searchQuery ); // we don't make case distinctions

		// Split out Latin words/chunks so we can look up the common ones and have easy
		// context for beginning and end of word patterns for the rest.
		$chunks = preg_split(
			'/([^a-z]+)/', // multiple *non-Latin* words, nums, punct in a chunk is fine
			$searchQuery,
			self::MAX_WORD_SPLIT,
			PREG_SPLIT_DELIM_CAPTURE // hold on to the non-Latin chunks
		);

		$changed = false;
		$nonLatinLastChunk = -1; // -1 == no last chunk; 0 == was Latin; 1 == was not Latin
		foreach ( $chunks as &$c ) {
			// if the last chunk was Latin, this one is not, so we can skip it
			if ( $nonLatinLastChunk == 0 ) {
				$nonLatinLastChunk = 1;
				continue;
			}

			// if this chunk has a-z characters (or we assume so because the last chunk
			// was non-Latin), map this chunk--otherwise skip it. (We can avoid preg_match
			// on every chunk once we establish the Latin/non-Latin alternation.)
			if ( $nonLatinLastChunk == 1 || preg_match( '/[a-z]/', $c ) ) {
				$nonLatinLastChunk = 0; // ok to skip the next non-Latin chunk
				// is this a known hard-coded common word?
				if ( isset( $this->latinToHindiWordMap[$c] ) ) {
					$c = $this->latinToHindiWordMap[$c];
					continue;
				}
				// do it the old-fashioned way
				$c = strtr( $c, $this->latinToHindiPhase1Map );
				$c = preg_replace( array_keys( $this->latinToHindiPhase2RegexMap ),
					array_values( $this->latinToHindiPhase2RegexMap ), $c );
				$c = strtr( $c, $this->latinToHindiPhase3Map );
				$c = strtr( $c, $this->latinToHindiPhase4Map );
				$c = preg_replace( array_keys( $this->latinToHindiPhase5RegexMap ),
					array_values( $this->latinToHindiPhase5RegexMap ), $c );
			}
		}
		$out = implode( '', $chunks );

		if ( preg_match( '/\p{Latin}/u', $out ) ) {
			// if there is any leftover Latin, it's a failure!
			// (rare, so we only check at the end)
			return [];
		}
		return [ $out ];
	}
}
