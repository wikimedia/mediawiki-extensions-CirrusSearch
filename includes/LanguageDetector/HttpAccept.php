<?php

namespace CirrusSearch\LanguageDetector;

use CirrusSearch;

/**
 * Try to detect language via Accept-Language header. Takes the
 * first accept-language that is not the current content language.
*/
class HttpAccept implements Detector {

	/**
	 * Current wiki language
	 * @var string
	 */
	protected $wikiLang;

	/**
	 * Current HTTP languages
	 * @var array
	 */
	protected $httpLang;

	public function __construct() {
		$this->wikiLang = $GLOBALS['wgContLang']->getCode();
		$this->httpLang = array_keys( $GLOBALS['wgRequest']->getAcceptLang() );
	}

	/**
	 * Detect language
	 *
	 * @param CirrusSearch $cirrus Searching class
	 * @param string $text Text to detect language
	 * @return string|null Preferred language, or null if none found
	 */
	public function detect( CirrusSearch $cirrus, $text ) {
		foreach ( $this->httpLang  as $lang ) {
			if ( $lang == '*' ) {
				continue;
			}
			list( $shortLang ) = explode( "-", $lang, 2 );
			if ( $shortLang !== $this->wikiLang ) {
				// return only the primary-tag, stripping the subtag
				// so the language to wiki map doesn't need all
				// possible combinations (quite a few).
				return $shortLang;
			}
		}
		return null;
	}
}
