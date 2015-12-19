<?php

namespace CirrusSearch\LanguageDetector;
use CirrusSearch;
use Elastica\Request;
use Elastica\Exception\ResponseException;
use MediaWiki\Logger\LoggerFactory;

/**
 * Try to detect language using langdetect plugin
 * See: https://github.com/jprante/elasticsearch-langdetect
 */
class ElasticSearch implements Detector {
	/* (non-PHPdoc)
	 * @see \CirrusSearch\LanguageDetector\Detector::detect()
	 */
	public function detect( CirrusSearch $cirrus, $text ) {
		$client = $cirrus->getConnection()->getClient();
		try {
			$response = $client->request( "_langdetect", Request::POST, $text );
		} catch ( ResponseException $e ) {
			// This happens when language detection is not configured
			LoggerFactory::getInstance( 'CirrusSearch' )->warning(
				"Could not connect to language detector: {exception}",
				array( "exception" => $e )
			);
			return null;
		}
		if ( $response->isOk() ) {
			$value = $response->getData();
			if ( $value && !empty( $value['languages'] ) ) {
				$langs = $value['languages'];
				if ( count( $langs ) == 1 ) {
					// TODO: add minimal threshold
					return $langs[0]['language'];
				}
				// FIXME: here I'm just winging it, should be something
				// that makes sense for multiple languages
				if ( count( $langs ) == 2) {
					if( $langs[0]['probability'] > 2*$langs[1]['probability'] ) {
						return $langs[0]['language'];
					}
				}
			}
		}
		return null;
	}
}
