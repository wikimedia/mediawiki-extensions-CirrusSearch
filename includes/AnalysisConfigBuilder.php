<?php

namespace CirrusSearch;

use \Language;

/**
 * Builds elasticsearch analysis config arrays.
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
class AnalysisConfigBuilder {
	/**
	 * Version number for the core analysis. Increment the major
	 * version when the analysis changes in an incompatible way,
	 * and change the minor version when it changes but isn't
	 * incompatible
	 */
	const VERSION = '0.6';

	/**
	 * Language code we're building analysis for
	 * @var string
	 */
	private $language;

	/**
	 * Constructor
	 * @param string $langCode The language code to build config for
	 * @param array(string) $plugins list of plugins installed in Elasticsearch
	 */
	public function __construct( $langCode, $plugins ) {
		$this->language = $langCode;
		foreach ( $this->elasticsearchLanguageAnalyzersFromPlugins as $plugin => $extra ) {
			if ( in_array( $plugin, $plugins ) ) {
				$this->elasticsearchLanguageAnalyzers = array_merge( $this->elasticsearchLanguageAnalyzers, $extra );
			}
		}
	}

	/**
	 * Build the analysis config.
	 * @return array the analysis config
	 */
	public function buildConfig() {
		$config = $this->customize( $this->defaults() );
		wfRunHooks( 'CirrusSearchAnalysisConfig', array( &$config ) );
		return $config;
	}

	/**
	 * Build an analysis config with sane defaults.
	 */
	private function defaults() {
		return array(
			'analyzer' => array(
				'text' => array(
					'type' => $this->getDefaultTextAnalyzerType(),
				),
				'text_search' => array(
					'type' => $this->getDefaultTextAnalyzerType(),
				),
				'plain' => array(
					// Surprisingly, the Lucene docs claim this works for
					// Chinese, Japanese, and Thai as well.
					// The difference between this and the 'standard'
					// analyzer is the lack of english stop words.
					'type' => 'custom',
					'tokenizer' => 'standard',
					'filter' => array( 'standard', 'lowercase' ),
				),
				'plain_search' => array(
					// In accent squashing languages this will not contain accent
					// squashing to allow searches with accents to only find accents
					// and searches without accents to find both.
					'type' => 'custom',
					'tokenizer' => 'standard',
					'filter' => array( 'standard', 'lowercase' ),
				),
				'suggest' => array(
					'type' => 'custom',
					'tokenizer' => 'standard',
					'filter' => array( 'standard', 'lowercase', 'suggest_shingle' ),
				),
				'near_match' => array(
					'type' => 'custom',
					'tokenizer' => 'no_splitting',
					'filter' => array( 'lowercase' ),
					'char_filter' => array( 'near_space_flattener' ),
				),
				'prefix' => array(
					'type' => 'custom',
					'tokenizer' => 'prefix',
					'filter' => array( 'lowercase' ),
					'char_filter' => array( 'near_space_flattener' ),
				),
				'word_prefix' => array(
					'type' => 'custom',
					'tokenizer' => 'standard',
					'filter' => array( 'standard', 'lowercase', 'prefix_ngram_filter' ),
				),
				'lowercase_keyword' => array(
					'type' => 'custom',
					'tokenizer' => 'no_splitting',
					'filter' => array( 'lowercase' ),
				),
			),
			'filter' => array(
				'suggest_shingle' => array(
					'type' => 'shingle',
					'min_shingle_size' => 2,
					'max_shingle_size' => 3,
					'output_unigrams' => true,
				),
				'lowercase' => array(
					'type' => 'lowercase',
				),
				'aggressive_splitting' => array(
					'type' => 'word_delimiter',
					'stem_english_possessive' => false,
					// 'catenate_words' => true, // Might be useful but causes errors on indexing
					// 'catenate_numbers' => true, // Might be useful but causes errors on indexing
					// 'catenate_all' => true, // Might be useful but causes errors on indexing
					'preserve_original' => false // "wi-fi-555" finds "wi-fi-555".  Not needed because of plain analysis.
				),
				'prefix_ngram_filter' => array(
					'type' => 'edgeNGram',
					'max_gram' => Searcher::MAX_TITLE_SEARCH,
				),
				'asciifolding' => array(
					'type' => 'asciifolding',
					'preserve_original' => true
				),
			),
			'tokenizer' => array(
				'prefix' => array(
					'type' => 'edgeNGram',
					'max_gram' => Searcher::MAX_TITLE_SEARCH,
				),
				'no_splitting' => array( // Just grab the whole term.
					'type' => 'keyword',
				),
			),
			'char_filter' => array(
				// Flattens things that are space like to spaces in the near_match style analyzersc
				'near_space_flattener' => array(
					'type' => 'mapping',
					'mappings' => array(
						"'=>\u0020",
						'’=>\u0020',
					),
				),
			),
		);
	}

	/**
	 * Customize the default config for the language.
	 */
	private function customize( $config ) {
		switch ( $this->language ) {
		// Please add languages in alphabetical order.
		case 'el':
			$config[ 'filter' ][ 'lowercase' ][ 'language' ] = 'greek';
			break;
		case 'en':
			$config[ 'filter' ][ 'possessive_english' ] = array(
				'type' => 'stemmer',
				'language' => 'possessive_english',
			);
			// Replace the default english analyzer with a rebuilt copy with asciifolding tacked on the end
			$config[ 'analyzer' ][ 'text' ] = array(
				'type' => 'custom',
				'tokenizer' => 'standard',
			);
			$filters = array();
			$filters[] = 'standard';
			$filters[] = 'aggressive_splitting';
			$filters[] = 'possessive_english';
			$filters[] = 'lowercase';
			$filters[] = 'stop';
			$filters[] = 'kstem';
			$filters[] = 'asciifolding';
			$config[ 'analyzer' ][ 'text' ][ 'filter' ] = $filters;

			// Add asciifolding to the the plain analyzer as well (but not plain_search)
			$config[ 'analyzer' ][ 'plain' ][ 'filter' ][] = 'asciifolding';
			// Add asciifolding to the prefix queries and incategory filters
			$config[ 'analyzer' ][ 'prefix' ][ 'filter' ][] = 'asciifolding';
			$config[ 'analyzer' ][ 'lowercase_keyword' ][ 'filter' ][] = 'asciifolding';
			$config[ 'analyzer' ][ 'near_match' ][ 'filter' ][] = 'asciifolding';

			// In English text_search is just a copy of text
			$config[ 'analyzer' ][ 'text_search' ] = $config[ 'analyzer' ][ 'text' ];
			break;
		case 'tr':
			$config[ 'filter' ][ 'lowercase' ][ 'language' ] = 'turkish';
			break;
		case 'he':
			// If the hebrew plugin kicked us over to the hebrew analyzer use its companion
			// analyzer for queries.
			if ( $config[ 'analyzer' ][ 'text_search' ][ 'type' ] === 'hebrew' ) {
				$config[ 'analyzer' ][ 'text_search' ][ 'type' ] = 'hebrew_exact';
			}
		}
		return $config;
	}

	/**
	 * Pick the appropriate default analyzer based on the language.  Rather than think of
	 * this as per language customization you should think of this as an effort to pick a
	 * reasonably default in case CirrusSearch isn't customized for the language.
	 * @return string the analyzer type
	 */
	public function getDefaultTextAnalyzerType() {
		// If we match a language exactly, use it
		if ( array_key_exists( $this->language, $this->elasticsearchLanguageAnalyzers ) ) {
			return $this->elasticsearchLanguageAnalyzers[ $this->language ];
		}

		// Try the fallback chain, excluding English
		$languages = Language::getFallbacksFor( $this->language );
		foreach ( $languages as $code ) {
			if ( $code !== 'en' && array_key_exists( $code, $this->elasticsearchLanguageAnalyzers ) ) {
				return $this->elasticsearchLanguageAnalyzers[ $code ];
			}
		}

		return 'default';
	}

	/**
	 * Languages for which elasticsearch provides a built in analyzer.  All
	 * other languages default to the default analyzer which isn't too good.  Note
	 * that this array is sorted alphabetically by value and sourced from
	 * http://www.elasticsearch.org/guide/reference/index-modules/analysis/lang-analyzer/
	 */
	private $elasticsearchLanguageAnalyzers = array(
		'ar' => 'arabic',
		'hy' => 'armenian',
		'eu' => 'basque',
		'pt-br' => 'brazilian',
		'bg' => 'bulgarian',
		'ca' => 'catalan',
		'zh-hans' => 'chinese',
		'ja' => 'cjk',
		'ko' => 'cjk',
		'cs' => 'czech',
		'da' => 'danish',
		'nl' => 'dutch',
		'en' => 'english',
		'fi' => 'finnish',
		'fr' => 'french',
		'gl' => 'galician',
		'de' => 'german',
		'el' => 'greek',
		'hi' => 'hindi',
		'hu' => 'hungarian',
		'id' => 'indonesian',
		'it' => 'italian',
		'nb' => 'norwegian',
		'nn' => 'norwegian',
		'fa' => 'persian',
		'pt' => 'portuguese',
		'ro' => 'romanian',
		'ru' => 'russian',
		'es' => 'spanish',
		'sv' => 'swedish',
		'tr' => 'turkish',
		'th' => 'thai',
	);

	private $elasticsearchLanguageAnalyzersFromPlugins = array(
		'analysis-stempel' => array( 'pl' => 'polish' ),
		'analysis-kuromoji' => array( 'ja' => 'kuromoji' ),
		'analysis-smartcn' => array( 'zh-hans' => 'smart_chinese' ),
		// This hasn't had a release in a while and seems to not work with the
		// current version of elasticsearch:
		'elasticsearch-analysis-hebrew' => array( 'he' => 'hebrew' ),
		// TODO Hebrew requires some special query handling....
	);
}
