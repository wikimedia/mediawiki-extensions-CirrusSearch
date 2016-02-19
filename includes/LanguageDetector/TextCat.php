<?php

namespace CirrusSearch\LanguageDetector;
use CirrusSearch;
use MediaWiki\Logger\LoggerFactory;

/**
 * Try to detect language with TextCat text categorizer
 */
class TextCat implements Detector {
	/* (non-PHPdoc)
	 * @see \CirrusSearch\LanguageDetector\Detector::detect()
	 */
	public function detect( CirrusSearch $cirrus, $text ) {
		$config = $cirrus->getConfig();
		if( empty( $config ) ) {
			// Should not happen
			return null;
		}
		$dir = $config->getElement('CirrusSearchTextcatModel');
		if( !$dir ) {
			return null;
		}
		if( !is_dir( $dir ) ) {
			LoggerFactory::getInstance( 'CirrusSearch' )->warning(
				"Bad directory for TextCat model: {dir}",
				array( "dir" => $dir )
			);
		}

		$textcat = new \TextCat( $dir );
		$languages = $textcat->classify( $text, $config->getElement( 'CirrusSearchTextcatLanguages' ) );
		if( !empty( $languages ) ) {
			// For now, just return the best option
			// TODO: thing what else we could do
			reset( $languages );
			return key( $languages );
		}
	}
}
