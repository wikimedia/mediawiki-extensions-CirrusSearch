<?php

namespace CirrusSearch\Elastica;

use CirrusSearch\LogChannel;
use Elastica\Connection;
use Elastica\Request;
use Elastica\Response;
use Elastica\Transport\Http;
use MediaWiki\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

class DeprecationLoggedHttp extends Http {
	/**
	 * Param on the Elastica connection that holds the X-Cluster-Name header of the
	 * last response. The transport object is made new for each request, but the
	 * connection is kept.
	 */
	public const CLUSTER_NAME_PARAM = 'cirrus_responding_cluster';

	/** @var LoggerInterface */
	private $logger;

	public function __construct( ?Connection $connection = null ) {
		parent::__construct( $connection );
		$this->logger = LoggerFactory::getInstance( LogChannel::DEPRECATION );
	}

	public function exec( Request $request, array $params ): Response {
		// Clear the value of the previous request, a failed request may not have headers.
		$this->getConnection()->setParam( self::CLUSTER_NAME_PARAM, null );
		return parent::exec( $request, $params );
	}

	/**
	 * @param resource $curlConnection
	 * @return void
	 */
	protected function _setupCurl( $curlConnection ): void {
		parent::_setupCurl( $curlConnection );
		// @phan-suppress-next-line PhanTypeMismatchArgumentInternal
		curl_setopt( $curlConnection, CURLOPT_HEADERFUNCTION, function ( $curl, $header ) {
			$this->handleHeader( $header );
			return strlen( $header );
		} );
	}

	public function handleHeader( string $header ): void {
		// Elasticsearch sends Warning, but seeing lowercase coming in from curl. Didn't
		// find docs confirming this is standard, do lowercase to have an expectation.
		$lower = strtolower( $header );
		if ( str_starts_with( $lower, 'warning:' ) ) {
			$this->logger->warning( $header, [
				// A bit awkward, but we want to log a stack trace without
				// being too specific about how that happens.
				'exception' => new \RuntimeException( $header ),
			] );
		} elseif ( str_starts_with( $lower, 'x-cluster-name:' ) ) {
			$value = trim( substr( $header, strlen( 'x-cluster-name:' ) ) );
			$this->getConnection()->setParam( self::CLUSTER_NAME_PARAM, $value );
		}
	}
}
