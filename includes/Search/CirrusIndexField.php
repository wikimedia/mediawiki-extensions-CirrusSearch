<?php

namespace CirrusSearch\Search;

use SearchEngine;
use SearchIndexFieldDefinition;
use SearchIndexField;
use CirrusSearch\SearchConfig;

/**
 * Basic ElasticSearch index field
 * @since 1.28
 */
abstract class CirrusIndexField extends SearchIndexFieldDefinition {

	/**
	 * Name of the type in Elastic
	 * @var string
	 */
	protected $typeName = 'unknown';

	/**
	 * @var SearchConfig
	 */
	protected $config;

	/**
	 * Specific mapping flags
	 * @var int
	 */
	protected $mappingFlags;

	/**
	 * CirrusIndexField constructor.
	 * @param string       $name
	 * @param int          $type
	 * @param SearchConfig $config
	 */
	public function __construct( $name, $type, SearchConfig $config ) {
		parent::__construct( $name, $type );
		$this->config = $config;
	}

	/**
	 * Set flags for specific mapping
	 * @param int $flags
	 * @return self
	 */
	public function setMappingFlags( $flags ) {
		$this->mappingFlags = $flags;
		return $this;
	}

	/**
	 * Get mapping for specific search engine
	 * @param SearchEngine $engine
	 * @return array
	 */
	public function getMapping( SearchEngine $engine ) {
		if ( !( $engine instanceof \CirrusSearch ) ) {
			throw new \LogicException( "Cannot map CirrusSearch fields for another engine." );
		}

		$config = [
			'type' => $this->typeName,
		];
		if ( $this->checkFlag( SearchIndexField::FLAG_NO_INDEX ) ) {
			$config['index'] = false;
		}
		return $config;
	}
}
