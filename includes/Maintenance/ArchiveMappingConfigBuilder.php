<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\Search\TextIndexField;

class ArchiveMappingConfigBuilder extends MappingConfigBuilder {
	/** @inheritDoc */
	public function buildConfig() {
		return [
			'dynamic' => false,
			'properties' => [
				'namespace' => $this->searchIndexFieldFactory
					->newLongField( 'namespace' )
					->getMapping( $this->engine ),
				'title' => $this->searchIndexFieldFactory->newStringField( 'title',
					TextIndexField::ENABLE_NORMS )->setMappingFlags( $this->flags )->getMapping( $this->engine ),
				'wiki' => $this->searchIndexFieldFactory
					->newKeywordField( 'wiki' )
					->getMapping( $this->engine ),
			],
		];
	}

	/**
	 * @return bool
	 */
	public function canOptimizeAnalysisConfig() {
		return true;
	}
}
