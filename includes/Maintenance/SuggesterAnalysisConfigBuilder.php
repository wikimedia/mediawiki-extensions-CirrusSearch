<?php

namespace CirrusSearch\Maintenance;

use \CirrusSearch\Searcher;
use \Hooks;
use \Language;

/**
 * Builds elasticsearch analysis config arrays for the completion suggester
 * index.
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

class SuggesterAnalysisConfigBuilder extends AnalysisConfigBuilder {
	const VERSION = "0.1";

	/**
	 * Constructor
	 * @param string $langCode The language code to build config for
	 * @param array(string) $plugins list of plugins installed in Elasticsearch
	 */
	public function __construct( $langCode, $plugins ) {
		parent::__construct( $langCode, $plugins );
	}

	/**
	 * Build and analysis config with sane defaults
	 */
	protected function defaults() {
		$defaults = array(
			'filter' => array(
				"stop_filter" => array(
					"type" => "stop",
					"stopwords" => "_none_",
					"remove_trailing" => "true"
				),
				"stop_filter_search" => array(
					"type" => "stop",
					"stopwords" => "_none_",
					"remove_trailing" => "false"
				),
				"asciifolding_preserve" => array(
					"type" => "asciifolding",
					"preserve_original" => "true",
				),
				"icu_normalizer" => array(
					"type" => "icu_normalizer",
					"name" => "nfkc_cf"
				),
				"50_token_limit" => array(
					"type" => "limit",
					"max_token_count" => "50"
				)
			),
			'analyzer' => array(
				"stop_analyzer" => array(
					"type" => "custom",
					"filter" => array(
						"standard",
						"lowercase",
						"stop_filter",
						"asciifolding_preserve",
						"50_token_limit"
					),
					"tokenizer" => "standard"
				),
				// We do not use ascii_folding when searching
				// Using ascii folding when searching will increase recall
				// but could be annoying for the user who makes effort to write
				// diacritics.
				"stop_analyzer_search" => array(
					"type" => "custom",
					"filter" => array(
						"standard",
						"lowercase",
						"stop_filter_search",
						"50_token_limit"
					),
					"tokenizer" => "standard"
				),
				"plain" => array(
					"type" => "custom",
					"filter" => array(
						"standard",
						"icu_normalizer",
						"asciifolding_preserve",
						"50_token_limit"
					),
					"tokenizer" => "standard"
				),
				"plain_search" => array(
					"type" => "custom",
					"filter" => array(
						"standard",
						"icu_normalizer",
						"50_token_limit"
					),
					"tokenizer" => "standard"
				)
			),
		);
		return $defaults;
	}

	private function customize( $config ) {
		$defaultStopSet = $this->getDefaultStopSet( $this->getLanguage() );
		$config['filter']['stop_filter']['stopwords'] = $defaultStopSet;
		$config['filter']['stop_filter_search']['stopwords'] = $defaultStopSet;
		if ( $this->isIcuAvailable() ) {
			foreach ( $config[ 'analyzer' ] as &$analyzer ) {
				if ( !isset( $analyzer[ 'filter'  ] ) ) {
					continue;
				}
				$analyzer[ 'filter' ] = array_map( function( $filter ) {
					if ( $filter === 'lowercase' ) {
						return 'icu_normalizer';
					}
					return $filter;
				}, $analyzer[ 'filter' ] );
			}
		}
		return $config;
	}

	/**
	 * Build the analysis config.
	 * @return array the analysis config
	 */
	public function buildConfig() {
		$config = $this->customize( $this->defaults() );
		return $config;
	}

	private static $stopwords = array(
		'ar' => '_arabic_',
		'hy' =>  '_armenian_',
		'eu' => '_basque_',
		'pt-br' => '_brazilian_',
		'bg' => '_bulgarian_',
		'ca' => '_catalan_',
		'cs' => '_czech_',
		'da' => '_danish_',
		'nl' => '_dutch_',
		'en' => '_english_',
		'en-ca' => '_english_',
		'en-gb' => '_english_',
		'simple' => '_english_',
		'fi' => '_finnish_',
		'fr' => '_french_',
		'gl' => '_galician_',
		'de' => '_german_',
		'el' => '_greek_',
		'hi' => '_hindi_',
		'hu' => '_hungarian_',
		'id' => '_indonesian_',
		'ga' => '_irish_',
		'it' => '_italian_',
		'lv' => '_latvian_',
		'nb' => '_norwegian_',
		'nn' => '_norwegian_',
		'fa' => '_persian_',
		'pt' => '_portuguese_',
		'ro' => '_romanian_',
		'ru' => '_russian_',
		'ckb' => '_sorani_',
		'es' => '_spanish_',
		'sv' => '_swedish_',
		'th' => '_thai_',
		'tr' => '_turkish_'
	);

	private function getDefaultStopSet( $lang ) {
		return isset( self::$stopwords[$lang] ) ? self::$stopwords[$lang] : '_none_';
	}

	public static function hasStopWords( $lang ) {
		return isset (self::$stopwords[$lang] );
	}
}
