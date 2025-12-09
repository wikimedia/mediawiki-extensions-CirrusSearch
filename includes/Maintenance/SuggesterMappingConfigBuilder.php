<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\SearchConfig;
use MediaWiki\MediaWikiServices;

/**
 * Builds search mapping configuration arrays for the suggester index.
 *
 * @license GPL-2.0-or-later
 */
class SuggesterMappingConfigBuilder {
	/**
	 * Version number for the core analysis. Increment the major
	 * version when the analysis changes in an incompatible way,
	 * and change the minor version when it changes but isn't
	 * incompatible
	 */
	public const VERSION = '3.0';

	/** @var SearchConfig */
	private $config;

	/**
	 * @param SearchConfig|null $config
	 */
	public function __construct( ?SearchConfig $config = null ) {
		$this->config = $config ?? MediaWikiServices::getInstance()->getConfigFactory()
			->makeConfig( 'CirrusSearch' );
	}

	/**
	 * @return array
	 */
	public function buildConfig() {
		$suggest = [
			'dynamic' => false,
			'_source' => [ 'enabled' => true ],
			'properties' => [
				'batch_id' => [ 'type' => 'long' ],
				'source_doc_id' => [ 'type' => 'keyword' ],
				// Sadly we can't reuse the same input
				// into multiple fields, it would help
				// us to save space since we now have
				// to store the source.
				'suggest' => [
					'type' => 'completion',
					'analyzer' => 'plain',
					'search_analyzer' => 'plain_search',
					'max_input_length' => 255,
				],
				'suggest-stop' => [
					'type' => 'completion',
					'analyzer' => 'stop_analyzer',
					'search_analyzer' => 'stop_analyzer_search',
					'preserve_separators' => false,
					'preserve_position_increments' => false,
					'max_input_length' => 255,
				],
			]
		];
		if ( $this->config->getElement( 'CirrusSearchCompletionSuggesterSubphrases', 'build' ) ) {
			$suggest['properties']['suggest-subphrases'] = [
				'type' => 'completion',
				'analyzer' => 'subphrases',
				'search_analyzer' => 'subphrases_search',
				'max_input_length' => 255,
			];

		}
		return $suggest;
	}

}
