<?php

namespace CirrusSearch\Elastica;

use Elastica\Bulk\Action;
use Elastica\Connection;
use Elastica\Request;
use Elastica\Response;
use Elastica\Transport\AbstractTransport;

/**
 * Painful hack that rewrites all bulk requests by attaching the "_type": "_doc" param to all
 * operations as it's mandatory with elasticsearch6.
 */
class ES6CompatTransportWrapper extends AbstractTransport {

	/**
	 * @var ?AbstractTransport
	 */
	private $transport;

	public function __construct( ?Connection $connection = null ) {
		parent::__construct( $connection );
	}

	public function exec( Request $request, array $params ): Response {
		$bulk = '/_bulk';
		$path = $request->getPath();
		if ( $path === "_bulk" || strrpos( $path, $bulk ) === strlen( $path ) - strlen( $bulk ) ) {
			$request = $this->wrapBulkRequest( $request );
		}
		return $this->transport()->exec( $request, $params );
	}

	private function wrapBulkRequest( Request $request ) {
		$data = $request->getData();
		if ( !is_string( $data ) ) {
			return $request;
		}
		/** @phan-suppress-next-line PhanTypeMismatchArgumentProbablyReal $data is string in case of bulk requests */
		return new Request( $request->getPath(), $request->getMethod(), $this->attachType( $data ),
			$request->getQuery(), $request->getConnection(), $request->getContentType() );
	}

	private function attachType( string $data ): string {
		$lines = explode( "\n", $data );
		$bulkData = "";
		$dataLine = false;
		foreach ( $lines as $line ) {
			if ( $line === "" ) {
				continue;
			}

			if ( !$dataLine ) {
				$line = json_decode( $line, true );
				foreach ( Action::$opTypes as $opType ) {
					if ( isset( $line[$opType] ) && !isset( $line[$opType]["_type"] ) ) {
						$line[$opType]["_type"] = "_doc";
						// delete operations do not have data
						$dataLine = $opType !== "delete";
						$line = json_encode( $line );
					}
				}
			} else {
				$dataLine = false;
			}
			$bulkData .= $line . "\n";
		}
		return $bulkData;
	}

	private function transport(): AbstractTransport {
		if ( $this->transport === null ) {
			$this->transport = AbstractTransport::create( $this->getParam( "wrapped_transport" ), $this->getConnection() );
		}
		return $this->transport;
	}
}
