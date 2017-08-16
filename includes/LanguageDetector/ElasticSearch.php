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
	/**
	 * Detect language
	 *
	 * @param CirrusSearch $cirrus Searching class
	 * @param string $text Text to detect language
	 * @return string|null Preferred language, or null if none found
	 */
	public function detect( CirrusSearch $cirrus, $text ) {
		$client = $cirrus->getConnection()->getClient();
		try {
			$response = $this->request( $client, $text );
		} catch ( ResponseException $e ) {
			// This happens when language detection is not configured
			LoggerFactory::getInstance( 'CirrusSearch' )->warning(
				"Could not connect to language detector: {exception}",
				[ "exception" => $e ]
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
				if ( count( $langs ) == 2 ) {
					if ( $langs[0]['probability'] > 2 * $langs[1]['probability'] ) {
						return $langs[0]['language'];
					}
				}
			}
		}
		return null;
	}

	/**
	 * @param \Elastica\Client $client
	 * @param string $text
	 * @return \Elastica\Response
	 * @suppress PhanTypeMismatchArgument The third parameter is typically
	 *  an array, but langdetect is special and takes a string instead.
	 */
	private function request( \Elastica\Client $client, $text ) {
		return $client->request( "_langdetect", Request::POST, $text );
	}
}
