<?php

namespace CirrusSearch\Api;

use CirrusSearch\Connection;
use CirrusSearch\SearchConfig;
use MediaWiki\Api\ApiBase;

/**
 * Dumps CirrusSearch mappings for easy viewing.
 *
 * @license GPL-2.0-or-later
 */
class MappingDump extends ApiBase {
	use ApiTrait;

	public function execute() {
		$conn = $this->getCirrusConnection();
		$indexPrefix = $this->getSearchConfig()->get( SearchConfig::INDEX_BASE_NAME );
		foreach ( $conn->getAllIndexSuffixes( null ) as $index ) {
			$mapping = $conn->getIndex( $indexPrefix, $index )->getMapping();
			$this->getResult()->addValue( null, $index, $mapping );
		}
		if ( $this->getSearchConfig()->isCompletionSuggesterEnabled() ) {
			$index = $conn->getIndex( $indexPrefix, Connection::TITLE_SUGGEST_INDEX_SUFFIX );
			if ( $index->exists() ) {
				$mapping = $index->getMapping();
				$this->getResult()->addValue( null, Connection::TITLE_SUGGEST_INDEX_SUFFIX, $mapping );
			}
		}
	}

	/** @inheritDoc */
	public function getAllowedParams() {
		return [];
	}

	/**
	 * Mark as internal. This isn't meant to be used by normal api users
	 * @return bool
	 */
	public function isInternal() {
		return true;
	}

	/**
	 * @see ApiBase::getExamplesMessages
	 * @return array
	 */
	protected function getExamplesMessages() {
		return [
			'action=cirrus-mapping-dump' =>
				'apihelp-cirrus-mapping-dump-example'
		];
	}

}
