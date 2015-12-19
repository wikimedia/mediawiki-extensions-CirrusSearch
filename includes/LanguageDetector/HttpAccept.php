<?php

namespace CirrusSearch\LanguageDetector;
use CirrusSearch;

/**
 * Try to detect language via Accept-Language header. Takes the
 * first accept-language that is not the current content language.
*/
class HttpAccept implements Detector {
	/* (non-PHPdoc)
	 * @see \CirrusSearch\LanguageDetector\Detector::detect()
	 */
	public function detect( CirrusSearch $cirrus, $text ) {
		$acceptLang = array_keys( $GLOBALS['wgRequest']->getAcceptLang() );
		$currentShortLang = $GLOBALS['wgContLang']->getCode();
		foreach ( $acceptLang as $lang ) {
			list( $shortLang ) = explode( "-", $lang, 2 );
			if ( $shortLang !== $currentShortLang ) {
				// return only the primary-tag, stripping the subtag
				// so the language to wiki map doesn't need all
				// possible combinations (quite a few).
				return $shortLang;
			}
		}
		return null;
	}
}
