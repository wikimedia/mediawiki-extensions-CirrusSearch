<?php

namespace CirrusSearch\Test;

use Elastica\Request;
use Elastica\Response;
use Elastica\Transport\AbstractTransport;

class PassThruTransport extends AbstractTransport {

	/** @var array */
	private $transportConfig;
	/** @var AbstractTransport */
	private $inner;
	/** @var Response[] */
	private $responses = [];

	/**
	 * @param AbstractTransport $inner
	 */
	public function __construct( $inner ) {
		if ( $inner instanceof AbstractTransport ) {
			$this->inner = $inner;
		} else {
			$this->transportConfig = $inner;
		}
	}

	/**
	 * @return Response[]
	 */
	public function getResponses() {
		return $this->responses;
	}

	/** @inheritDoc */
	public function exec( Request $request, array $params ) {
		$response = $this->inner->exec( $request, $params );
		$this->responses[] = $response;

		return $response;
	}

	/** @inheritDoc */
	public function getConnection() {
		return $this->inner->getConnection();
	}

	/** @inheritDoc */
	public function setConnection( \Elastica\Connection $connection ) {
		if ( $this->inner ) {
			$this->inner->setConnection( $connection );
		} else {
			$this->inner = AbstractTransport::create(
				$this->transportConfig['transport'],
				$connection,
				$this->transportConfig['params']
			);
		}

		return $this;
	}

	/** @inheritDoc */
	public function toArray() {
		return $this->inner->toArray();
	}

	/** @inheritDoc */
	public function setParam( $key, $value ) {
		$this->inner->setParam( $key, $value );

		return $this;
	}

	/** @inheritDoc */
	public function setParams( array $params ) {
		$this->inner->setParams( $params );

		return $this;
	}

	/** @inheritDoc */
	public function addParam( $key, $value ) {
		$this->inner->addParam( $key, $value );

		return $this;
	}

	/** @inheritDoc */
	public function getParam( $key ) {
		return $this->inner->getParam( $key );
	}

	/** @inheritDoc */
	public function hasParam( $key ) {
		return $this->inner->hasParam( $key );
	}

	/** @inheritDoc */
	public function getParams() {
		return $this->inner->getParams();
	}
}
