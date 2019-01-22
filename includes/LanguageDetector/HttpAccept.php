<?php

namespace CirrusSearch\LanguageDetector;

use CirrusSearch\SearchConfig;

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
	 * @var string[]
	 */
	protected $httpLang;

	/**
	 * HttpAccept constructor.
	 * @param string $languageCode host wiki language code
	 * @param \WebRequest $request
	 */
	public function __construct( $languageCode, \WebRequest $request ) {
		$this->wikiLang = $languageCode;
		$this->httpLang = array_keys( $request->getAcceptLang() );
	}

	/**
	 * Detect language
	 *
	 * @param string $text Text to detect language
	 * @return string|null Preferred language, or null if none found
	 */
	public function detect( $text ) {
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

	/**
	 * @param SearchConfig $config
	 * @param \WebRequest $request
	 * @return Detector
	 */
	public static function build( SearchConfig $config, \WebRequest $request ) {
		return new self( $config->get( 'LanguageCode' ), $request );
	}
}
