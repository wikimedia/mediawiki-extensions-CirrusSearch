<?php

namespace CirrusSearch\Api;

use CirrusSearch\Connection;
use CirrusSearch\SearchConfig;
use MediaWiki\MediaWikiServices;

trait ApiTrait {
	/** @var Connection */
	private $connection;
	/** @var SearchConfig */
	private $searchConfig;

	/**
	 * @return Connection
	 */
	public function getCirrusConnection() {
		if ( $this->connection === null ) {
			$this->connection = new Connection( $this->getSearchConfig() );
		}
		return $this->connection;
	}

	/**
	 * @return SearchConfig
	 */
	protected function getSearchConfig() {
		if ( $this->searchConfig === null ) {
			$this->searchConfig = MediaWikiServices::getInstance()
				->getConfigFactory()
				->makeConfig( 'CirrusSearch' );
		}
		return $this->searchConfig;
	}
}
