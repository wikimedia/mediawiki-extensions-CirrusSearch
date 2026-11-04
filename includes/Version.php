<?php

namespace CirrusSearch;

use MediaWiki\Status\Status;

/**
 * Fetch the Elasticsearch version
 *
 * @license GPL-2.0-or-later
 */
class Version extends ElasticsearchIntermediary {
	use SearcherTrait;

	public function __construct( Connection $conn ) {
		parent::__construct( $conn, null, 0 );
	}

	/**
	 * Get the version of Elasticsearch with which we're communicating.
	 *
	 * @return Status<array<string,string>> version number as a string
	 */
	public function get() {
		try {
			$this->start( $this->newLog( 'fetching elasticsearch version', 'version' ) );
			// If this times out the cluster is in really bad shape but we should still
			// check it.
			$this->connection->setTimeout( $this->getClientTimeout( 'version' ) );
			$response = $this->connection->getClient()->request( '' );
			self::throwIfNotOk( $this->connection, $response );
			$this->success();
		} catch ( \Elastica\Exception\ExceptionInterface $e ) {
			return $this->failure( $e );
		}
		$version = $response->getData()['version'];
		return Status::newGood( [
			'distribution' => $version['distribution'] ?? 'elasticsearch',
			'version' => $version['number'],
		] );
	}
}
