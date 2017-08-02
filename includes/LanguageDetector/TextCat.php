<?php

namespace CirrusSearch\LanguageDetector;

use CirrusSearch;
use MediaWiki\Logger\LoggerFactory;

/**
 * Try to detect language with TextCat text categorizer
 */
class TextCat implements Detector {
	/**
	 * Detect language
	 *
	 * @param CirrusSearch $cirrus Searching class
	 * @param string $text Text to detect language
	 * @return string|null Preferred language, or null if none found
	 */
	public function detect( CirrusSearch $cirrus, $text ) {
		$config = $cirrus->getConfig();
		if ( empty( $config ) ) {
			// Should not happen
			return null;
		}
		$dirs = $config->getElement( 'CirrusSearchTextcatModel' );
		if ( !$dirs ) {
			return null;
		}
		if ( !is_array( $dirs ) ) { // backward compatibility
			$dirs = [ $dirs ];
		}
		foreach ( $dirs as $dir ) {
			if ( !is_dir( $dir ) ) {
				LoggerFactory::getInstance( 'CirrusSearch' )->warning(
					"Bad directory for TextCat model: {dir}",
					[ "dir" => $dir ]
				);
			}
		}

		$textcat = new \TextCat( $dirs );

		$textcatConfig = $config->getElement( 'CirrusSearchTextcatConfig' );
		if ( $textcatConfig ) {
			if ( isset( $textcatConfig['maxNgrams'] ) ) {
				$textcat->setMaxNgrams( intval( $textcatConfig['maxNgrams'] ) );
			}
			if ( isset( $textcatConfig['maxReturnedLanguages'] ) ) {
				$textcat->setMaxReturnedLanguages( intval( $textcatConfig['maxReturnedLanguages'] ) );
			}
			if ( isset( $textcatConfig['resultsRatio'] ) ) {
				$textcat->setResultsRatio( floatval( $textcatConfig['resultsRatio'] ) );
			}
			if ( isset( $textcatConfig['minInputLength'] ) ) {
				$textcat->setMinInputLength( intval( $textcatConfig['minInputLength'] ) );
			}
			if ( isset( $textcatConfig['maxProportion'] ) ) {
				$textcat->setMaxProportion( floatval( $textcatConfig['maxProportion'] ) );
			}
			if ( isset( $textcatConfig['langBoostScore'] ) ) {
				$textcat->setLangBoostScore( floatval( $textcatConfig['langBoostScore'] ) );
			}

			if ( isset( $textcatConfig['numBoostedLangs'] ) &&
				$config->getElement( 'CirrusSearchTextcatLanguages' )
			) {
				$textcat->setBoostedLangs( array_slice(
					$config->getElement( 'CirrusSearchTextcatLanguages' ),
					0, $textcatConfig['numBoostedLangs'] ) );
			}
		}
		$languages = $textcat->classify( $text, $config->getElement( 'CirrusSearchTextcatLanguages' ) );
		if ( !empty( $languages ) ) {
			// For now, just return the best option
			// TODO: think what else we could do
			reset( $languages );
			return key( $languages );
		}

		return null;
	}
}
