<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\SearchConfig;
use MediaWiki\MediaWikiServices;

/**
 * Builds elasticsearch mapping configuration arrays for the suggester index.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */
class SuggesterMappingConfigBuilder {
	/**
	 * Version number for the core analysis. Increment the major
	 * version when the analysis changes in an incompatible way,
	 * and change the minor version when it changes but isn't
	 * incompatible
	 */
	const VERSION = '1.1';

	/** @var SearchConfig */
	private $config;

	/**
	 * Constructor
	 * @param SearchConfig $config
	 */
	public function __construct( SearchConfig $config = null ) {
		if ( is_null( $config ) ) {
			$config =
				MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'CirrusSearch' );
		}
		$this->config = $config;
	}

	/**
	 * @return array[]
	 */
	public function buildConfig() {
		$geoContext = [
			'location' => [
				'type' => 'geo',
				'precision' => [ 6, 4, 3 ], // ~ 1km, 10km, 100km
				'neighbors' => true,
			]
		];
		$suggest = [
			'dynamic' => false,
			'_all' => [ 'enabled' => false ],
			'_source' => ['enabled' => false ],
			'properties' => [
				'batch_id' => [ 'type' => 'long' ],
				'suggest' => [
					'type' => 'completion',
					'analyzer' => 'plain',
					'search_analyzer' => 'plain_search',
					'payloads' => false
				],
				'suggest-stop' => [
					'type' => 'completion',
					'analyzer' => 'stop_analyzer',
					'search_analyzer' => 'stop_analyzer_search',
					'preserve_separators' => false,
					'preserve_position_increments' => false,
					'payloads' => false
				],
				'suggest-geo' => [
					'type' => 'completion',
					'analyzer' => 'plain',
					'search_analyzer' => 'plain_search',
					'payloads' => false,
					'context' => $geoContext
				],
				'suggest-stop-geo' => [
					'type' => 'completion',
					'analyzer' => 'stop_analyzer',
					'search_analyzer' => 'stop_analyzer_search',
					'preserve_separators' => false,
					'preserve_position_increments' => false,
					'payloads' => false,
					'context' => $geoContext
				]
			]
		];
		if ( $this->config->getElement( 'CirrusSearchCompletionSuggesterSubphrases', 'build' ) ) {
			$suggest['properties']['suggest-subphrases'] = [
				'type' => 'completion',
				'analyzer' => 'subphrases',
				'search_analyzer' => 'subphrases_search',
				'payloads' => false
			];

		}
		return [ \CirrusSearch\Connection::TITLE_SUGGEST_TYPE_NAME => $suggest ];
	}

}
