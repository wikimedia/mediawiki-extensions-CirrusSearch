<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\SearchConfig;
use CirrusSearch\Searcher;
use Hooks;
use Language;
use MediaWiki\MediaWikiServices;

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
	const VERSION = '0.12';

	/**
	 * @var string Language code we're building analysis for
	 */
	private $language;

	/**
	 * @var boolean is the icu plugin available?
	 */
	private $icu;

	/**
	 * @var boolean true if icu folding is requested and available
	 */
	private $icuFolding;

	/**
	 * @var array Similarity algo (tf/idf, bm25, etc) configuration
	 */
	private $similarity;

	/**
	 * @var SearchConfig cirrus config
	 */
	protected $config;

	/**
	 * @param string $langCode The language code to build config for
	 * @param string[] $plugins list of plugins installed in Elasticsearch
	 * @param SearchConfig $config
	 */
	public function __construct( $langCode, array $plugins, SearchConfig $config = null ) {
		$this->language = $langCode;
		foreach ( $this->elasticsearchLanguageAnalyzersFromPlugins as $plugin => $extra ) {
			if ( in_array( $plugin, $plugins ) ) {
				$this->elasticsearchLanguageAnalyzers = array_merge( $this->elasticsearchLanguageAnalyzers, $extra );
			}
		}
		$this->icu = in_array( 'analysis-icu', $plugins );
		if ( is_null ( $config ) ) {
			$config = MediaWikiServices::getInstance()
				->getConfigFactory()
				->makeConfig( 'CirrusSearch' );
		}
		$this->similarity = $config->getElement(
			'CirrusSearchSimilarityProfiles',
			$config->get( 'CirrusSearchSimilarityProfile' )
		);

		$this->config = $config;
		$this->icuFolding = $this->shouldActivateIcuFolding( $plugins );
	}

	/**
	 * Determine if ascii folding should be used
	 * @param string[] $plugins list of installed elasticsearch plugins
	 * @return bool true if icu folding should be enabled
	 */
	private function shouldActivateIcuFolding( array $plugins ) {
		if ( !$this->icu || !in_array( 'extra', $plugins ) ) {
			// ICU folding requires the icu plugin and the extra plugin
			return false;
		}
		$in_config = $this->config->get( 'CirrusSearchUseIcuFolding' );
		// BC code, this config var was originally a simple boolean
		if ( $in_config === true ) {
			$in_config = 'yes';
		}
		if ( $in_config === false ) {
			$in_config = 'no';
		}
		switch( $in_config ) {
		case 'yes': return true;
		case 'no': return false;
		case 'default':
			if ( isset( $this->languagesWithIcuFolding[$this->language] ) ) {
				return $this->languagesWithIcuFolding[$this->language];
			}
		default: return false;
		}
	}

	/**
	 * Build the analysis config.
	 *
	 * @return array the analysis config
	 */
	public function buildConfig() {
		$config = $this->customize( $this->defaults() );
		Hooks::run( 'CirrusSearchAnalysisConfig', [ &$config ] );
		if ( $this->icuFolding ) {
			$config = $this->enableICUFolding( $config );
		}
		$config = $this->fixAsciiFolding( $config );
		return $config;
	}

	/**
	 * Build the similarity config
	 *
	 * @return array|null the similarity config
	 */
	public function buildSimilarityConfig() {
		if ( $this->similarity != null && isset ( $this->similarity['similarity'] ) ) {
			return $this->similarity['similarity'];
		}
		return null;
	}

	/**
	 * Activate ICU folding instead of asciifolding
	 * @param mixed[] $config
	 * @return mixed[] update config
	 */
	public function enableICUFolding( array $config ) {
		$unicodeSetFilter = $this->getICUSetFilter();
		$filter = [
			'type' => 'icu_folding',
		];
		if ( !empty( $unicodeSetFilter ) ) {
			$filter['unicodeSetFilter'] = $unicodeSetFilter;
		}
		$config['filter']['icu_folding'] = $filter;

		// Adds a simple nfkc normalizer for cases where
		// we preserve original but the lowercase filter
		// is not used before
		$config['filter']['icu_nfkc_normalization'] = [
			'type' => 'icu_normalizer',
			'name' => 'nfkc',
		];

		$newfilters = [];
		foreach( $config['analyzer'] as $name => $value ) {
			if ( isset( $value['type'] ) && $value['type'] != 'custom' ) {
				continue;
			}
			if ( !isset( $value['filter'] ) ) {
				continue;
			}
			if ( in_array( 'asciifolding', $value['filter'] ) ) {
				$newfilters[$name] = $this->switchFiltersToICUFolding( $value['filter'] );
			}
			if ( in_array( 'asciifolding_preserve', $value['filter'] ) ) {
				$newfilters[$name] = $this->switchFiltersToICUFoldingPreserve( $value['filter'] );
			}
		}

		foreach( $newfilters as $name => $filters ) {
			$config['analyzer'][$name]['filter'] = $filters;
		}
		// Explicitly enable icu_folding on plain analyzers if it's not
		// already enabled
		foreach( ['plain'] as $analyzer ) {
			if ( !isset( $config['analyzer'][$analyzer] ) ) {
				continue;
			}
			if ( !isset( $config['analyzer'][$analyzer]['filter'] ) ) {
				$config['analyzer'][$analyzer]['filter'] = [];
			}
			$config['analyzer'][$analyzer]['filter'] =
				$this->switchFiltersToICUFoldingPreserve(
					$config['analyzer'][$analyzer]['filter'], true );
		}

		return $config;
	}

	/**
	 * Replace occurrence of asciifolding to icu_folding
	 * @param string[] list of filters
	 * @return string[] new list of filters
	 */
	private function switchFiltersToICUFolding( array $filters ) {
		return array_replace( $filters,
			[ array_search( 'asciifolding', $filters ) => 'icu_folding' ] );
	}

	/**
	 * Replace occurrence of asciifolding_preserve with a set
	 * of compatible filters to enable icu_folding
	 * @param string[] list of filters
	 * @param bool $append append icu_folding even if asciifolding is not present
	 * @return string[] new list of filters
	 */
	private function switchFiltersToICUFoldingPreserve( array $filters, $append = false ) {
		if ( in_array( 'icu_folding', $filters ) ) {
			// ICU folding already here
			return $filters;
		}
		$ap_idx = array_search( 'asciifolding_preserve', $filters );
		if ( $ap_idx === false && $append ) {
			$ap_idx = count( $filters );
			// fake an asciifolding_preserve so we can
			// reuse code that replaces it
			$filters[] = 'asciifolding_preserve';
		}
		if ( $ap_idx === false ) {
			return $filters;
		}
		// with ICU lowercase is replaced by icu_normalizer/nfkc_cf
		// thus unicode normalization is already done.
		$lc_idx = array_search( 'icu_normalizer', $filters );
		$newfilters = [];
		if ( $lc_idx === false || $lc_idx > $ap_idx ) {
			// If lowercase is not detected before we
			// will have to do some icu normalization
			// this is to prevent preserving "un-normalized"
			// unicode chars.
			$newfilters[] = 'icu_nfkc_normalization';
		}
		$newfilters[] = 'preserve_original_recorder';
		$newfilters[] = 'icu_folding';
		$newfilters[] = 'preserve_original';
		array_splice( $filters, $ap_idx, 1, $newfilters );
		return $filters;
	}

	/**
	 * Return the list of chars to exclude from ICU folding
	 * @return string|null
	 */
	protected function getICUSetFilter() {
		if ( $this->config->get( 'CirrusSearchICUFoldingUnicodeSetFilter' ) !== null ) {
			return $this->config->get( 'CirrusSearchICUFoldingUnicodeSetFilter' );
		}
		switch( $this->language ) {
		// @todo: complete the default filters per language
		case 'fi': return '[^åäöÅÄÖ]';
		case 'ru': return '[^йЙ]';
		case 'sw': return '[^åäöÅÄÖ]';
		default: return null;
		}
	}

	/**
	 * Build an analysis config with sane defaults.
	 *
	 * @return array
	 */
	private function defaults() {
		$defaults = [
			'analyzer' => [
				'text' => [
					// These defaults are not applied to non-custom
					// analysis chains, i.e., those that use the
					// default language analyzers on 'text'
					'type' => $this->getDefaultTextAnalyzerType(),
					'char_filter' => [ 'word_break_helper' ],
				],
				'text_search' => [
					// These defaults are not applied to non-custom
					// analysis chains, i.e., those that use the
					// default language analyzers on 'text_search'
					'type' => $this->getDefaultTextAnalyzerType(),
					'char_filter' => [ 'word_break_helper' ],
				],
				'plain' => [
					// Surprisingly, the Lucene docs claim this works for
					// Chinese, Japanese, and Thai as well.
					// The difference between this and the 'standard'
					// analyzer is the lack of english stop words.
					'type' => 'custom',
					'tokenizer' => 'standard',
					'filter' => [ 'standard', 'lowercase' ],
					'char_filter' => [ 'word_break_helper' ],
				],
				'plain_search' => [
					// In accent squashing languages this will not contain accent
					// squashing to allow searches with accents to only find accents
					// and searches without accents to find both.
					'type' => 'custom',
					'tokenizer' => 'standard',
					'filter' => [ 'standard', 'lowercase' ],
					'char_filter' => [ 'word_break_helper' ],
				],
				// Used by ShortTextIndexField
				'short_text' => [
					'type' => 'custom',
					'tokenizer' => 'whitespace',
					'filter' => [
						'lowercase',
						'aggressive_splitting',
						'asciifolding_preserve',
					],
				],
				'short_text_search' => [
					'type' => 'custom',
					'tokenizer' => 'whitespace',
					'filter' => [
						'lowercase',
						'aggressive_splitting',
					],
				],
				'source_text_plain' => [
					'type' => 'custom',
					'tokenizer' => 'standard',
					'filter' => [ 'standard', 'lowercase' ],
					'char_filter' => [ 'word_break_helper_source_text' ],
				],
				'source_text_plain_search' => [
					'type' => 'custom',
					'tokenizer' => 'standard',
					'filter' => [ 'standard', 'lowercase' ],
					'char_filter' => [ 'word_break_helper_source_text' ],
				],
				'suggest' => [
					'type' => 'custom',
					'tokenizer' => 'standard',
					'filter' => [ 'standard', 'lowercase', 'suggest_shingle' ],
				],
				'suggest_reverse' => [
					'type' => 'custom',
					'tokenizer' => 'standard',
					'filter' => [ 'standard', 'lowercase', 'suggest_shingle', 'reverse' ],
				],
				'token_reverse' => [
					'type' => 'custom',
					'tokenizer' => 'no_splitting',
					'filter' => ['reverse']
				],
				'near_match' => [
					'type' => 'custom',
					'tokenizer' => 'no_splitting',
					'filter' => [ 'lowercase' ],
					'char_filter' => [ 'near_space_flattener' ],
				],
				'near_match_asciifolding' => [
					'type' => 'custom',
					'tokenizer' => 'no_splitting',
					'filter' => [ 'lowercase', 'asciifolding' ],
					'char_filter' => [ 'near_space_flattener' ],
				],
				'prefix' => [
					'type' => 'custom',
					'tokenizer' => 'prefix',
					'filter' => [ 'lowercase' ],
					'char_filter' => [ 'near_space_flattener' ],
				],
				'prefix_asciifolding' => [
					'type' => 'custom',
					'tokenizer' => 'prefix',
					'filter' => [ 'lowercase', 'asciifolding' ],
					'char_filter' => [ 'near_space_flattener' ],
				],
				'word_prefix' => [
					'type' => 'custom',
					'tokenizer' => 'standard',
					'filter' => [ 'standard', 'lowercase', 'prefix_ngram_filter' ],
				],
				'lowercase_keyword' => [
					'type' => 'custom',
					'tokenizer' => 'no_splitting',
					'filter' => [ 'lowercase' ],
				],
				'trigram' => [
					'type' => 'custom',
					'tokenizer' => 'trigram',
					'filter' => [ 'lowercase' ],
				],
			],
			'filter' => [
				'suggest_shingle' => [
					'type' => 'shingle',
					'min_shingle_size' => 2,
					'max_shingle_size' => 3,
					'output_unigrams' => true,
				],
				'lowercase' => [
					'type' => 'lowercase',
				],
				'aggressive_splitting' => [
					'type' => 'word_delimiter',
					'stem_english_possessive' => false,
					// 'catenate_words' => true, // Might be useful but causes errors on indexing
					// 'catenate_numbers' => true, // Might be useful but causes errors on indexing
					// 'catenate_all' => true, // Might be useful but causes errors on indexing
					'preserve_original' => false // "wi-fi-555" finds "wi-fi-555".  Not needed because of plain analysis.
				],
				'prefix_ngram_filter' => [
					'type' => 'edgeNGram',
					'max_gram' => Searcher::MAX_TITLE_SEARCH,
				],
				'asciifolding' => [
					'type' => 'asciifolding',
					'preserve_original' => false
				],
				'asciifolding_preserve' => [
					'type' => 'asciifolding',
					'preserve_original' => true
				],
			],
			'tokenizer' => [
				'prefix' => [
					'type' => 'edgeNGram',
					'max_gram' => Searcher::MAX_TITLE_SEARCH,
				],
				'no_splitting' => [ // Just grab the whole term.
					'type' => 'keyword',
				],
				'trigram' => [
					'type' => 'nGram',
					'min_gram' => 3,
					'max_gram' => 3,
				],
			],
			'char_filter' => [
				// Flattens things that are space like to spaces in the near_match style analyzers
				'near_space_flattener' => [
					'type' => 'mapping',
					'mappings' => [
						"'=>\u0020",       // Useful for finding names
						'\u2019=>\u0020',  // Unicode right single quote
						'\u02BC=>\u0020',  // Unicode modifier letter apostrophe
						'_=>\u0020',       // Mediawiki loves _ and people are used to it but it usually means space
						'-=>\u0020',       // Useful for finding hyphenated names unhyphenated
					],
				],
				// Converts things that don't always count as word breaks into spaces which always
				// count as word breaks.
				'word_break_helper' => [
					'type' => 'mapping',
					'mappings' => [
						'_=>\u0020',
						// These are more useful for code:
						'.=>\u0020',
						'(=>\u0020',
						')=>\u0020',
					],
				],
				'word_break_helper_source_text' => [
					'type' => 'mapping',
					'mappings' => [
						'_=>\u0020',
						// These are more useful for code:
						'.=>\u0020',
						'(=>\u0020',
						')=>\u0020',
						':=>\u0020', // T145023
					],
				],
			],
		];
		foreach ( $defaults[ 'analyzer' ] as &$analyzer ) {
			if ( $analyzer[ 'type' ] === 'default' ) {
				$analyzer = [
					'type' => 'custom',
					'tokenizer' => 'standard',
					'filter' => [ 'standard', 'lowercase' ],
				];
			}
		}
		if ( $this->icu ) {
			$defaults[ 'filter' ][ 'icu_normalizer' ] = [
				'type' => 'icu_normalizer',
				'name' => 'nfkc_cf',
			];
		}

		return $defaults;
	}

	/**
	 * Customize the default config for the language.
	 *
	 * @param array $config
	 * @return array
	 */
	private function customize( $config ) {
		switch ( $this->getDefaultTextAnalyzerType() ) {
		// Please add languages in alphabetical order.
		case 'english':
			$config[ 'filter' ][ 'possessive_english' ] = [
				'type' => 'stemmer',
				'language' => 'possessive_english',
			];
			// Replace the default English analyzer with a rebuilt copy with asciifolding inserted before stemming
			$config[ 'analyzer' ][ 'text' ] = [
				'type' => 'custom',
				'tokenizer' => 'standard',
				'char_filter' => [ 'word_break_helper' ],
			];
			$filters = [];
			$filters[] = 'standard';
			$filters[] = 'aggressive_splitting';
			$filters[] = 'possessive_english';
			$filters[] = 'lowercase';
			$filters[] = 'stop';
			$filters[] = 'asciifolding';
			$filters[] = 'kstem';
			$filters[] = 'custom_stem';
			$config[ 'analyzer' ][ 'text' ][ 'filter' ] = $filters;

			// Add asciifolding_preserve to the the plain analyzer as well (but not plain_search)
			$config[ 'analyzer' ][ 'plain' ][ 'filter' ][] = 'asciifolding_preserve';
			// Add asciifolding_preserve filters
			$config[ 'analyzer' ][ 'lowercase_keyword' ][ 'filter' ][] = 'asciifolding_preserve';

			// In English text_search is just a copy of text
			$config[ 'analyzer' ][ 'text_search' ] = $config[ 'analyzer' ][ 'text' ];

			// Setup custom stemmer
			$config[ 'filter' ][ 'custom_stem' ] = [
				'type' => 'stemmer_override',
				'rules' => <<<STEMMER_RULES
guidelines => guideline
STEMMER_RULES
				,
			];
			break;
		case 'french':
			// Add asciifolding_preserve to filters
			$config[ 'analyzer' ][ 'lowercase_keyword' ][ 'filter' ][] = 'asciifolding_preserve';

			$config[ 'char_filter' ][ 'french_charfilter' ] = [
				'type' => 'mapping',
				'mappings' => [
					'\u0130=>I',		// dotted I
					'\u02BC=>\u0027',	// modifier apostrophe to straight quote T146804
				],
			];
			$config[ 'filter' ][ 'french_elision' ] = [
				'type' => 'elision',
				'articles_case' => true,
				'articles' => [
					'l', 'm', 't', 'qu', 'n', 's',
					'j', 'd', 'c', 'jusqu', 'quoiqu',
					'lorsqu', 'puisqu',
				],
			];
			$config[ 'filter' ][ 'french_stop' ] = [
				'type' => 'stop',
				'stopwords' => '_french_',
			];
			$config[ 'filter' ][ 'french_stemmer' ] = [
				'type' => 'stemmer',
				'language' => 'light_french',
			];

			// Replace the default French analyzer with a rebuilt copy with asciifolding_preserve tacked on the end
			// T141216 / T142620
			$config[ 'analyzer' ][ 'text' ] = [
				'type' => 'custom',
				'tokenizer' => 'standard',
				'char_filter' => [ 'french_charfilter' ],
			];

			$filters = [];
			$filters[] = 'french_elision';
			$filters[] = 'lowercase';
			$filters[] = 'french_stop';
			$filters[] = 'french_stemmer';
			$filters[] = 'asciifolding_preserve';
			$config[ 'analyzer' ][ 'text' ][ 'filter' ] = $filters;

			// In French text_search is just a copy of text
			$config[ 'analyzer' ][ 'text_search' ] = $config[ 'analyzer' ][ 'text' ];
			break;
		case 'greek':
			$config[ 'filter' ][ 'lowercase' ][ 'language' ] = 'greek';
			break;
		case 'hebrew':
			// If the hebrew plugin kicked us over to the hebrew analyzer use its companion
			// analyzer for queries.
			if ( $config[ 'analyzer' ][ 'text_search' ][ 'type' ] === 'hebrew' ) {
				$config[ 'analyzer' ][ 'text_search' ][ 'type' ] = 'hebrew_exact';
			}
			break;
		case 'italian':
			$config[ 'filter' ][ 'italian_elision' ] = [
				'type' => 'elision',
				'articles' => [
					'c',
					'l',
					'all',
					'dall',
					'dell',
					'nell',
					'sull',
					'coll',
					'pell',
					'gl',
					'agl',
					'dagl',
					'degl',
					'negl',
					'sugl',
					'un',
					'm',
					't',
					's',
					'v',
					'd'
				],
			];
			$config[ 'filter' ][ 'italian_stop' ] = [
				'type' => 'stop',
				'stopwords' => '_italian_',
			];
			$config[ 'filter' ][ 'light_italian_stemmer' ] = [
				'type' => 'stemmer',
				'language' => 'light_italian',
			];
			// Replace the default Italian analyzer with a rebuilt copy with asciifolding tacked on the end
			$config[ 'analyzer' ][ 'text' ] = [
				'type' => 'custom',
				'tokenizer' => 'standard',
				'char_filter' => [ 'word_break_helper' ],
			];
			$filters = [];
			$filters[] = 'standard';
			$filters[] = 'italian_elision';
			$filters[] = 'aggressive_splitting';
			$filters[] = 'lowercase';
			$filters[] = 'italian_stop';
			$filters[] = 'light_italian_stemmer';
			$filters[] = 'asciifolding';
			$config[ 'analyzer' ][ 'text' ][ 'filter' ] = $filters;

			// Add asciifolding_preserve to the the plain analyzer as well (but not plain_search)
			$config[ 'analyzer' ][ 'plain' ][ 'filter' ][] = 'asciifolding_preserve';
			// Add asciifolding_preserve to filters
			$config[ 'analyzer' ][ 'lowercase_keyword' ][ 'filter' ][] = 'asciifolding_preserve';

			// In Italian text_search is just a copy of text
			$config[ 'analyzer' ][ 'text_search' ] = $config[ 'analyzer' ][ 'text' ];
			break;
		case 'russian':
			$config[ 'char_filter' ][ 'russian_charfilter' ] = [
				'type' => 'mapping',
				'mappings' => [
					'\u0301=>',		// combining acute accent, only used to show stress T102298
					'\u0130=>I',	// dotted I (fix regression caused by unpacking)
				],
			];

			$config[ 'char_filter' ][ 'near_space_flattener' ][ 'mappings' ][] = '\u0301=>'; // T102298

			// The Russian analyzer is also used for Ukrainian and Rusyn for now, so processing that's
			// very specific to Russian should be separated out
			if ($this->language == 'ru') {
				// T124592 fold ё=>е and Ё=>Е, precomposed or with combining diacritic
				$config[ 'char_filter' ][ 'russian_charfilter' ][ 'mappings' ][] = '\u0435\u0308=>\u0435';
				$config[ 'char_filter' ][ 'russian_charfilter' ][ 'mappings' ][] = '\u0415\u0308=>\u0415';
				$config[ 'char_filter' ][ 'russian_charfilter' ][ 'mappings' ][] = '\u0451=>\u0435';
				$config[ 'char_filter' ][ 'russian_charfilter' ][ 'mappings' ][] = '\u0401=>\u0415';

				$config[ 'char_filter' ][ 'near_space_flattener' ][ 'mappings' ][] = '\u0451=>\u0435';
				$config[ 'char_filter' ][ 'near_space_flattener' ][ 'mappings' ][] = '\u0401=>\u0415';
				$config[ 'char_filter' ][ 'near_space_flattener' ][ 'mappings' ][] = '\u0435\u0308=>\u0435';
				$config[ 'char_filter' ][ 'near_space_flattener' ][ 'mappings' ][] = '\u0415\u0308=>\u0415';
			}

			// Ukrainian uses the Russian analyzer for now, but we want some Ukrainian-specific processing
			if ($this->language == 'uk') {
				// T146358 map right quote and modifier letter apostrophe to apostrophe
				$config[ 'char_filter' ][ 'russian_charfilter' ][ 'mappings' ][] = '\u02BC=>\u0027';
				$config[ 'char_filter' ][ 'russian_charfilter' ][ 'mappings' ][] = '\u2019=>\u0027';
			}

			// Drop acute stress marks and fold ё=>е everywhere
			$config[ 'analyzer' ][ 'plain' ][ 'char_filter' ][] = 'russian_charfilter';
			$config[ 'analyzer' ][ 'plain_search' ][ 'char_filter' ][] = 'russian_charfilter';

			$config[ 'analyzer' ][ 'suggest' ][ 'char_filter' ][] = 'russian_charfilter';
			$config[ 'analyzer' ][ 'suggest_reverse' ][ 'char_filter' ][] = 'russian_charfilter';



			// unpack built-in Russian analyzer and add character filter T102298 / T124592
			$config[ 'filter' ][ 'russian_stop' ] = [
				'type' => 'stop',
				'stopwords' => '_russian_',
			];
			$config[ 'filter' ][ 'russian_stemmer' ] = [
				'type' => 'stemmer',
				'language' => 'russian',
			];
			$config[ 'analyzer' ][ 'text' ] = [
				'type' => 'custom',
				'tokenizer' => 'standard',
				'char_filter' => [ 'russian_charfilter' ],
			];
			$filters = [];
			$filters[] = 'lowercase';
			$filters[] = 'russian_stop';
			$filters[] = 'russian_stemmer';
			$config[ 'analyzer' ][ 'text' ][ 'filter' ] = $filters;

			// In Russian text_search is just a copy of text
			$config[ 'analyzer' ][ 'text_search' ] = $config[ 'analyzer' ][ 'text' ];
			break;
		case 'turkish':
			$config[ 'filter' ][ 'lowercase' ][ 'language' ] = 'turkish';
			break;
		}
		if ( $this->icu ) {
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
	 * Workaround for https://issues.apache.org/jira/browse/LUCENE-7468
	 * The preserve_original duplicates token even if they are
	 * not modified, leading to more space used and wrong term frequencies.
	 * Workaround is to append a unique filter to remove the dups.
	 * (made public for unit tests)
	 *
	 * @param mixed[] $config
	 * @return mixed[] update mapping
	 */
	public function fixAsciiFolding( array $config ) {
		$needDedupFilter = false;
		foreach( $config['analyzer'] as $name => &$value ) {
			if ( isset( $value['type'] ) && $value['type'] != 'custom' ) {
				continue;
			}
			if ( !isset( $value['filter'] ) ) {
				continue;
			}
			$ascii_idx = array_search( 'asciifolding_preserve', $value['filter'] );
			if ( $ascii_idx !== FALSE ) {
				$needDedupFilter = true;
				array_splice( $value['filter'], $ascii_idx + 1, 0, ['dedup_asciifolding'] );
			}
		}
		if ( $needDedupFilter ) {
			$config['filter']['dedup_asciifolding'] = [
				'type' => 'unique',
				'only_on_same_position' => true,
			];
		}
		return $config;
	}

	/**
	 * Pick the appropriate default analyzer based on the language.  Rather than think of
	 * this as per language customization you should think of this as an effort to pick a
	 * reasonably default in case CirrusSearch isn't customized for the language.
	 *
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
	 * @return boolean true if the icu analyzer is available.
	 */
	public function isIcuAvailable() {
		return $this->icu;
	}

	/**
	 * Languages for which elasticsearch provides a built in analyzer.  All
	 * other languages default to the default analyzer which isn't too good.  Note
	 * that this array is sorted alphabetically by value and sourced from
	 * http://www.elasticsearch.org/guide/reference/index-modules/analysis/lang-analyzer/
	 *
	 * @var string[]
	 */
	private $elasticsearchLanguageAnalyzers = [
		'ar' => 'arabic',
		'hy' => 'armenian',
		'eu' => 'basque',
		'pt-br' => 'brazilian',
		'bg' => 'bulgarian',
		'ca' => 'catalan',
		'ja' => 'cjk',
		'ko' => 'cjk',
		'cs' => 'czech',
		'da' => 'danish',
		'nl' => 'dutch',
		'en' => 'english',
		'en-ca' => 'english',
		'en-gb' => 'english',
		'simple' => 'english',
		'fi' => 'finnish',
		'fr' => 'french',
		'gl' => 'galician',
		'de' => 'german',
		'el' => 'greek',
		'hi' => 'hindi',
		'hu' => 'hungarian',
		'id' => 'indonesian',
		'lt' => 'lithuanian',
		'lv' => 'latvian',
		'ga' => 'irish',
		'it' => 'italian',
		'nb' => 'norwegian',
		'nn' => 'norwegian',
		'fa' => 'persian',
		'pt' => 'portuguese',
		'ro' => 'romanian',
		'ru' => 'russian',
		'ckb' => 'sorani',
		'es' => 'spanish',
		'sv' => 'swedish',
		'tr' => 'turkish',
		'th' => 'thai',
	];

	/**
	 * @var bool[] indexed by language code, languages where ICU folding
	 * can be enabled by default
	 */
	private $languagesWithIcuFolding = [];

	/**
	 * @var array[]
	 */
	private $elasticsearchLanguageAnalyzersFromPlugins = [
		'analysis-stempel' => [ 'pl' => 'polish' ],
		'analysis-kuromoji' => [ 'ja' => 'kuromoji' ],
		'analysis-smartcn' => [ 'zh-hans' => 'smartcn' ],
		// This hasn't had a release in a while and seems to not work with the
		// current version of elasticsearch:
		'elasticsearch-analysis-hebrew' => [ 'he' => 'hebrew' ],
		// TODO Hebrew requires some special query handling....
	];

	/**
	 * @return string MediaWiki language code
	 */
	public function getLanguage() {
		return $this->language;
	}

	/**
	 * @return bool true if ICU Folding is enabled
	 */
	public function isIcuFolding() {
		return $this->icuFolding;
	}
}
