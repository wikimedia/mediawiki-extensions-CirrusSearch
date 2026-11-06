<?php

namespace CirrusSearch;

use Elastica\Client;

/**
 * Request log for requests that update the elasticsearch cluster. All
 * update requests are done through bulk actions.
 */
class BulkUpdateRequestLog extends BaseRequestLog {
	/**
	 * @var \Elastica\Client
	 */
	private $client;

	/**
	 * @var \Elastica\Response|null
	 */
	private $lastResponse;

	/**
	 * @var \Elastica\Response|null
	 */
	private $response;

	/**
	 * @param Client $client
	 * @param string $description
	 * @param string $queryType
	 * @param array $extra
	 * @param array<string, string> $metricLabels
	 */
	public function __construct( Client $client, $description, $queryType, array $extra = [], array $metricLabels = [] ) {
		parent::__construct( $description, $queryType, $extra, $metricLabels );
		$this->client = $client;
		$this->lastResponse = $client->getLastResponse();
	}

	public function finish( string $clusterName, ?string $errorType, bool $partial ) {
		if ( $this->response ) {
			throw new \RuntimeException( 'Finishing a log more than once' );
		}
		parent::finish( $clusterName, $errorType, $partial );
		$response = $this->client->getLastResponse();
		$this->response = $response === $this->lastResponse ? null : $response;
		$this->lastResponse = null;
	}

	/** @inheritDoc */
	public function isCachedResponse() {
		return false;
	}

	/** @inheritDoc */
	public function getElasticTookMs() {
		return $this->response?->getData()['took'] ?? -1;
	}

	/**
	 * @return array
	 */
	public function getLogVariables() {
		return [
			'queryType' => $this->queryType,
			'tookMs' => $this->getTookMs(),
		] + $this->extra;
	}

	/**
	 * We could generate multiple items for each bulk update that was sent..but
	 * doesn't seem necessary (yet).
	 *
	 * @return array[]
	 */
	public function getRequests() {
		return [ $this->getLogVariables() ];
	}
}
