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
		global $wgCirrusSearchTextcatModel;
		if( empty( $wgCirrusSearchTextcatModel ) ) {
			return null;
		}
		if( !is_dir( $wgCirrusSearchTextcatModel ) ) {
			LoggerFactory::getInstance( 'CirrusSearch' )->warning(
				"Bad directory for TextCat model: {dir}",
				array( "dir" => $wgCirrusSearchTextcatModel )
			);
		}
		$textcat = new \TextCat( $wgCirrusSearchTextcatModel );
		$languages = $textcat->classsify();
		if( !empty( $languages ) ) {
			// For now, just return the best option
			// TODO: thing what else we could do
			reset( $languages );
			return key( $languages );
		}
	}
}
