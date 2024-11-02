<?php

namespace CirrusSearch\Elastica;

use Elastica\Connection;
use Elastica\Transport\Http;
use MediaWiki\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

class DeprecationLoggedHttp extends Http {

	/** @var LoggerInterface */
	private $logger;

	public function __construct( ?Connection $connection = null ) {
		parent::__construct( $connection );
		$this->logger = LoggerFactory::getInstance( 'CirrusSearchDeprecation' );
	}

	/**
	 * @param resource $curlConnection
	 * @return void
	 */
	protected function _setupCurl( $curlConnection ): void {
		parent::_setupCurl( $curlConnection );
		// @phan-suppress-next-line PhanTypeMismatchArgumentInternal
		curl_setopt( $curlConnection, CURLOPT_HEADERFUNCTION, function ( $curl, $header ) {
			// Elasticsearch sends Warning, but seeing lowercase coming in from curl. Didn't
			// find docs confirming this is standard, do lowercase to have an expectation.
			if ( str_starts_with( strtolower( $header ), 'warning:' ) ) {
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
