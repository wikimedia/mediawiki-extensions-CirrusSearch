<?php

namespace CirrusSearch\Elastica;

use Elastica\Connection;
use Elastica\Transport\Http;
use MediaWiki\Logger\LoggerFactory;

class DeprecationLoggedHttp extends Http {

	private $logger;

	public function __construct( Connection $connection = null ) {
		parent::__construct( $connection );
		$this->logger = LoggerFactory::getInstance( 'CirrusSearchDeprecation' );
	}

	private function strStartsWith( $str, $prefix ) {
		// TODO: php 8 use str_starts_with
		return substr( $str, 0, strlen( $prefix ) ) === $prefix;
	}

	protected function _setupCurl( $curlConnection ) {
		parent::_setupCurl( $curlConnection );
		curl_setopt( $curlConnection, CURLOPT_HEADERFUNCTION, function ( $curl, $header ) {
			// Elasticsearch sends Warning, but seeing lowercase coming in from curl. Didn't
			// find docs confirming this is standard, do lowercase to have an expectation.
			if ( $this->strStartsWith( strtolower( $header ), 'warning:' ) ) {
				$this->logger->warning( $header, [
					// A bit awkward, but we want to log a stack trace without
					// being too specific about how that happens.
					'exception' => new \RuntimeException( $header ),
				] );
			}
			return strlen( $header );
		} );
	}
}
