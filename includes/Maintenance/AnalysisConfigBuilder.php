<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch;
use CirrusSearch\Profile\SearchProfileService;
use CirrusSearch\SearchConfig;
use Hooks;
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
	 * Maximum number of characters allowed in keyword terms.
	 */
	const KEYWORD_IGNORE_ABOVE = 5000;

	/**
	 * @var boolean is the icu plugin available?
	 */
	private $icu;

	/**
	 * @var array Similarity algo (tf/idf, bm25, etc) configuration
	 */
	private $similarity;

	/**
	 * @var SearchConfig cirrus config
	 */
	protected $config;
	/**
	 * @var string[]
	 */
	private $plugins;

	/**
	 * @var string
	 */
	protected $defaultLanguage;

	/**
	 * @param string $langCode The language code to build config for
	 * @param string[] $plugins list of plugins installed in Elasticsearch
	 * @param SearchConfig $config
	 */
	public function __construct( $langCode, array $plugins, SearchConfig $config = null ) {
		$this->defaultLanguage = $langCode;
		$this->plugins = $plugins;
		foreach ( $this->elasticsearchLanguageAnalyzersFromPlugins as $pluginSpec => $extra ) {
			$pluginsPresent = 1;
			$pluginList = explode( ',', $pluginSpec );
			foreach ( $pluginList as $plugin ) {
				if ( !in_array( $plugin, $plugins ) ) {
					$pluginsPresent = 0;
					break;
				}
			}
			if ( $pluginsPresent ) {
				$this->elasticsearchLanguageAnalyzers = array_merge( $this->elasticsearchLanguageAnalyzers, $extra );
			}
		}
		$this->icu = in_array( 'analysis-icu', $plugins );
		if ( is_null( $config ) ) {
			$config = MediaWikiServices::getInstance()
				->getConfigFactory()
				->makeConfig( 'CirrusSearch' );
		}
		$this->similarity = $config->getProfileService()->loadProfile( SearchProfileService::SIMILARITY );

		$this->config = $config;
	}

	/**
	 * Determine if ascii folding should be used
	 * @param string $language Config language
	 * @return bool true if icu folding should be enabled
	 */
	public function shouldActivateIcuFolding( $language ) {
		if ( !$this->icu || !in_array( 'extra', $this->plugins ) ) {
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
		switch ( $in_config ) {
		case 'yes':
			return true;
		case 'no':
			return false;
		case 'default':
			if ( isset( $this->languagesWithIcuFolding[$language] ) ) {
				return $this->languagesWithIcuFolding[$language];
			}
		default:
			return false;
		}
	}

	/**
	 * Determine if the icu tokenizer can be enabled
	 * @param string $language Config language
	 * @return bool
	 */
	public function shouldActivateIcuTokenization( $language ) {
		if ( !$this->icu ) {
			// requires the icu plugin
			return false;
		}
		$in_config = $this->config->get( 'CirrusSearchUseIcuTokenizer' );
		switch ( $in_config ) {
		case 'yes':
			return true;
		case 'no':
			return false;
		case 'default':
			if ( isset( $this->languagesWithIcuTokenization[$language] ) ) {
				return $this->languagesWithIcuTokenization[$language];
			}
		default:
			return false;
		}
	}

	/**
	 * Build the analysis config.
	 *
	 * @param string $language Config language
	 * @return array the analysis config
	 */
	public function buildConfig( $language = null ) {
		if ( $language === null ) {
			$language = $this->defaultLanguage;
		}
		$config = $this->customize( $this->defaults( $language ), $language );
		Hooks::run( 'CirrusSearchAnalysisConfig', [ &$config, $this ] );
		if ( $this->shouldActivateIcuTokenization( $language ) ) {
			$config = $this->enableICUTokenizer( $config );
		}
		if ( $this->shouldActivateIcuFolding( $language ) ) {
			$config = $this->enableICUFolding( $config, $language );
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
		if ( $this->similarity != null && isset( $this->similarity['similarity'] ) ) {
			return $this->similarity['similarity'];
		}
		return null;
	}
	/**
	 * replace the standard tokenizer with icu_tokenizer
	 * @param mixed[] $config
	 * @return mixed[] update config
	 */
	public function enableICUTokenizer( array $config ) {
		foreach ( $config['analyzer'] as $name => &$value ) {
			if ( isset( $value['type'] ) && $value['type'] != 'custom' ) {
				continue;
			}
			if ( isset( $value['tokenizer'] ) && 'standard' === $value['tokenizer'] ) {
				$value['tokenizer'] = 'icu_tokenizer';
			}
		}
		return $config;
	}

	/**
	 * Activate ICU folding instead of asciifolding
	 * @param mixed[] $config
	 * @param string $language Config language
	 * @return mixed[] update config
	 */
	public function enableICUFolding( array $config, $language ) {
		$unicodeSetFilter = $this->getICUSetFilter( $language );
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
		foreach ( $config['analyzer'] as $name => $value ) {
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

		foreach ( $newfilters as $name => $filters ) {
			$config['analyzer'][$name]['filter'] = $filters;
		}
		// Explicitly enable icu_folding on plain analyzers if it's not
		// already enabled
		foreach ( [ 'plain' ] as $analyzer ) {
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
	 * @param string[] $filters
	 * @return string[] new list of filters
	 */
	private function switchFiltersToICUFolding( array $filters ) {
		return array_replace( $filters,
			[ array_search( 'asciifolding', $filters ) => 'icu_folding' ] );
	}

	/**
	 * Replace occurrence of asciifolding_preserve with a set
	 * of compatible filters to enable icu_folding
	 * @param string[] $filters
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
	 * @param string $language Config language
	 * @return null|string
	 */
	protected function getICUSetFilter( $language ) {
		if ( $this->config->get( 'CirrusSearchICUFoldingUnicodeSetFilter' ) !== null ) {
			return $this->config->get( 'CirrusSearchICUFoldingUnicodeSetFilter' );
		}
		switch ( $language ) {
		// @todo: complete the default filters per language
		// For Swedish (sv), see https://www.mediawiki.org/wiki/User:TJones_(WMF)/T160562
		// For Serbian (sr), see https://www.mediawiki.org/wiki/User:TJones_(WMF)/T183015
		case 'fi':
			return '[^åäöÅÄÖ]';
		case 'ru':
			return '[^йЙ]';
		case 'sv':
			return '[^åäöÅÄÖ]';
		case 'sr':
			return '[^ĐđŽžĆćŠšČč]';
		default:
			return null;
		}
	}

	/**
	 * Build an analysis config with sane defaults.
	 *
	 * @param string $language Config language
	 * @return array
	 */
	private function defaults( $language ) {
		$defaults = [
			'analyzer' => [
				'text' => [
					// These defaults are not applied to non-custom
					// analysis chains, i.e., those that use the
					// default language analyzers on 'text'
					'type' => $this->getDefaultTextAnalyzerType( $language ),
					'char_filter' => [ 'word_break_helper' ],
				],
				'text_search' => [
					// These defaults are not applied to non-custom
					// analysis chains, i.e., those that use the
					// default language analyzers on 'text_search'
					'type' => $this->getDefaultTextAnalyzerType( $language ),
					'char_filter' => [ 'word_break_helper' ],
				],
				'plain' => [
					// Surprisingly, the Lucene docs claim this works for
					// Chinese, Japanese, and Thai as well.
					// The difference between this and the 'standard'
					// analyzer is the lack of english stop words.
					'type' => 'custom',
					'tokenizer' => 'standard',
					'filter' => [ 'lowercase' ],
					'char_filter' => [ 'word_break_helper' ],
				],
				'plain_search' => [
					// In accent squashing languages this will not contain accent
					// squashing to allow searches with accents to only find accents
					// and searches without accents to find both.
					'type' => 'custom',
					'tokenizer' => 'standard',
					'filter' => [ 'lowercase' ],
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
					'filter' => [ 'lowercase' ],
					'char_filter' => [ 'word_break_helper_source_text' ],
				],
				'source_text_plain_search' => [
					'type' => 'custom',
					'tokenizer' => 'standard',
					'filter' => [ 'lowercase' ],
					'char_filter' => [ 'word_break_helper_source_text' ],
				],
				'suggest' => [
					'type' => 'custom',
					'tokenizer' => 'standard',
					'filter' => [ 'lowercase', 'suggest_shingle' ],
				],
				'suggest_reverse' => [
					'type' => 'custom',
					'tokenizer' => 'standard',
					'filter' => [ 'lowercase', 'suggest_shingle', 'reverse' ],
				],
				'token_reverse' => [
					'type' => 'custom',
					'tokenizer' => 'no_splitting',
					'filter' => [ 'reverse' ]
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
					'filter' => [ 'truncate_keyword', 'lowercase', 'asciifolding' ],
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
					'filter' => [ 'lowercase', 'prefix_ngram_filter' ],
				],
				'keyword' => [
					'type' => 'custom',
					'tokenizer' => 'no_splitting',
					'filter' => [ 'truncate_keyword' ],
				],
				'lowercase_keyword' => [
					'type' => 'custom',
					'tokenizer' => 'no_splitting',
					'filter' => [ 'truncate_keyword', 'lowercase' ],
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
					'max_gram' => CirrusSearch::MAX_TITLE_SEARCH,
				],
				'asciifolding' => [
					'type' => 'asciifolding',
					'preserve_original' => false
				],
				'asciifolding_preserve' => [
					'type' => 'asciifolding',
					'preserve_original' => true
				],
				// The 'keyword' type in ES seems like a hack
				// and doesn't allow normalization (like lowercase)
				// prior to 5.2. Instead we consistently use 'text'
				// and truncate where necessary.
				'truncate_keyword' => [
					'type' => 'truncate',
					'length' => self::KEYWORD_IGNORE_ABOVE,
				],
			],
			'tokenizer' => [
				'prefix' => [
					'type' => 'edgeNGram',
					'max_gram' => CirrusSearch::MAX_TITLE_SEARCH,
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
					'filter' => [ 'lowercase' ],
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
	 * @param string $language Config language
	 * @return array
	 */
	private function customize( $config, $language ) {
		switch ( $this->getDefaultTextAnalyzerType( $language ) ) {
		// Please add languages in alphabetical order.
		case 'chinese':
			// See https://www.mediawiki.org/wiki/User:TJones_(WMF)/T158203
			$config[ 'char_filter' ][ 'stconvertfix' ] = [
				// hack for STConvert errors
				// see https://github.com/medcl/elasticsearch-analysis-stconvert/issues/13
				'type' => 'mapping',
				'mappings' => [
					'\u606d\u5f18=>\u606d \u5f18',
					'\u5138=>\u3469',
				],
			];
			$config[ 'char_filter' ][ 'tsconvert' ] = [
				'type' => 'stconvert',
				'delimiter' => '#',
				'keep_both' => false,
				'convert_type' => 't2s',
			];
			$config[ 'filter' ][ 'smartcn_stop' ] = [
				// SmartCN converts lots of punctuation to "," but we don't want to index it
				'type' => 'stop',
				'stopwords' => [ "," ],
			];
			$config[ 'analyzer' ][ 'text' ] = [
				'type' => 'custom',
				'tokenizer' => 'smartcn_tokenizer',
				'char_filter' => [ 'stconvertfix', 'tsconvert' ],
				'filter' => [ 'smartcn_stop', 'lowercase' ],
			];

			$config[ 'analyzer' ][ 'text_search' ] = $config[ 'analyzer' ][ 'text' ];
			$config[ 'analyzer' ][ 'plain' ][ 'filter' ] = [ 'smartcn_stop', 'lowercase' ];
			$config[ 'analyzer' ][ 'plain_search' ][ 'filter' ] = $config[ 'analyzer' ][ 'plain' ][ 'filter' ];
			break;
		case 'english':
			$config[ 'char_filter' ][ 'kana_map' ] = [
				// Map hiragana to katakana, currently only for English
				// See https://www.mediawiki.org/wiki/User:TJones_(WMF)/T176197
					'type' => 'mapping',
					'mappings' => [
						"\u3041=>\u30a1", "\u3042=>\u30a2", "\u3043=>\u30a3",
						"\u3044=>\u30a4", "\u3045=>\u30a5", "\u3046=>\u30a6",
						"\u3094=>\u30f4", "\u3047=>\u30a7", "\u3048=>\u30a8",
						"\u3049=>\u30a9", "\u304a=>\u30aa", "\u3095=>\u30f5",
						"\u304b=>\u30ab", "\u304c=>\u30ac", "\u304d=>\u30ad",
						"\u304e=>\u30ae", "\u304f=>\u30af", "\u3050=>\u30b0",
						"\u3096=>\u30f6", "\u3051=>\u30b1", "\u3052=>\u30b2",
						"\u3053=>\u30b3", "\u3054=>\u30b4", "\u3055=>\u30b5",
						"\u3056=>\u30b6", "\u3057=>\u30b7", "\u3058=>\u30b8",
						"\u3059=>\u30b9", "\u305a=>\u30ba", "\u305b=>\u30bb",
						"\u305c=>\u30bc", "\u305d=>\u30bd", "\u305e=>\u30be",
						"\u305f=>\u30bf", "\u3060=>\u30c0", "\u3061=>\u30c1",
						"\u3062=>\u30c2", "\u3063=>\u30c3", "\u3064=>\u30c4",
						"\u3065=>\u30c5", "\u3066=>\u30c6", "\u3067=>\u30c7",
						"\u3068=>\u30c8", "\u3069=>\u30c9", "\u306a=>\u30ca",
						"\u306b=>\u30cb", "\u306c=>\u30cc", "\u306d=>\u30cd",
						"\u306e=>\u30ce", "\u306f=>\u30cf", "\u3070=>\u30d0",
						"\u3071=>\u30d1", "\u3072=>\u30d2", "\u3073=>\u30d3",
						"\u3074=>\u30d4", "\u3075=>\u30d5", "\u3076=>\u30d6",
						"\u3077=>\u30d7", "\u3078=>\u30d8", "\u3079=>\u30d9",
						"\u307a=>\u30da", "\u307b=>\u30db", "\u307c=>\u30dc",
						"\u307d=>\u30dd", "\u307e=>\u30de", "\u307f=>\u30df",
						"\u3080=>\u30e0", "\u3081=>\u30e1", "\u3082=>\u30e2",
						"\u3083=>\u30e3", "\u3084=>\u30e4", "\u3085=>\u30e5",
						"\u3086=>\u30e6", "\u3087=>\u30e7", "\u3088=>\u30e8",
						"\u3089=>\u30e9", "\u308a=>\u30ea", "\u308b=>\u30eb",
						"\u308c=>\u30ec", "\u308d=>\u30ed", "\u308e=>\u30ee",
						"\u308f=>\u30ef", "\u3090=>\u30f0", "\u3091=>\u30f1",
						"\u3092=>\u30f2", "\u3093=>\u30f3",
					],
				];

			$config[ 'filter' ][ 'possessive_english' ] = [
				'type' => 'stemmer',
				'language' => 'possessive_english',
			];
			// Replace the default English analyzer with a rebuilt copy with asciifolding inserted before stemming
			$config[ 'analyzer' ][ 'text' ] = [
				'type' => 'custom',
				'tokenizer' => 'standard',
				'char_filter' => [ 'word_break_helper', 'kana_map' ],
			];
			$filters = [];
			$filters[] = 'aggressive_splitting';
			$filters[] = 'possessive_english';
			$filters[] = 'lowercase';
			$filters[] = 'stop';
			$filters[] = 'asciifolding'; // See https://www.mediawiki.org/wiki/User:TJones_(WMF)/T142037
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
			// See https://www.mediawiki.org/wiki/User:TJones_(WMF)/T142620
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
			$config[ 'analyzer' ][ 'text' ] = [
				'type' => 'custom',
				'tokenizer' => 'hebrew',
				'filter' => [ 'niqqud', 'hebrew_lemmatizer', 'lowercase', 'asciifolding' ],
			];
			$config[ 'analyzer' ][ 'text_search' ] = $config[ 'analyzer' ][ 'text' ];
			break;
		case 'italian':
			$config[ 'filter' ][ 'italian_elision' ] = [
				'type' => 'elision',
				'articles' => [
					'c', 'l', 'all', 'dall', 'dell', 'nell', 'sull',
					'coll', 'pell', 'gl', 'agl', 'dagl', 'degl', 'negl',
					'sugl', 'un', 'm', 't', 's', 'v', 'd'
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
		case 'japanese':
			// See https://www.mediawiki.org/wiki/User:TJones_(WMF)/T166731
			$config[ 'char_filter' ][ 'fullwidthnumfix' ] = [
				// pre-convert fullwidth numbers because Kuromoji tokenizer treats them weirdly
				'type' => 'mapping',
				'mappings' => [
					"\uff10=>0", "\uff11=>1", "\uff12=>2", "\uff13=>3",
					"\uff14=>4", "\uff15=>5", "\uff16=>6", "\uff17=>7",
					"\uff18=>8", "\uff19=>9",
				],
			];

			$config[ 'analyzer' ][ 'text' ] = [
				'type' => 'custom',
				'char_filter' => [ 'fullwidthnumfix' ],
				'tokenizer' => 'kuromoji_tokenizer',
			];

			$filters = [];
			$filters[] = 'kuromoji_baseform';
			$filters[] = 'cjk_width';
			$filters[] = 'ja_stop';
			$filters[] = 'kuromoji_stemmer';
			$filters[] = 'lowercase';
			$config[ 'analyzer' ][ 'text' ][ 'filter' ] = $filters;

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

			// The Russian analyzer is also used for Rusyn for now, so processing that's
			// very specific to Russian should be separated out
			if ( $language === 'ru' ) {
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

			// Drop acute stress marks and fold ё=>е everywhere
			// See https://www.mediawiki.org/wiki/User:TJones_(WMF)/T124592
			$config[ 'analyzer' ][ 'plain' ][ 'char_filter' ][] = 'russian_charfilter';
			$config[ 'analyzer' ][ 'plain_search' ][ 'char_filter' ][] = 'russian_charfilter';

			$config[ 'analyzer' ][ 'suggest' ][ 'char_filter' ][] = 'russian_charfilter';
			$config[ 'analyzer' ][ 'suggest_reverse' ][ 'char_filter' ][] = 'russian_charfilter';

			// unpack built-in Russian analyzer and add character filter
			// See https://www.mediawiki.org/wiki/User:TJones_(WMF)/T124592
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
		case 'serbian':
			// Unpack default analyzer to add Serbian stemming and custom folding
			// See https://www.mediawiki.org/wiki/User:TJones_(WMF)/T183015
			$config[ 'filter' ][ 'scstemmer' ] = [
				'type' => 'serbian_stemmer',
			];

			$config['analyzer']['text'] = [
				'type' => 'custom',
				'tokenizer' => 'standard',
				'filter' => [
					'lowercase',
					'asciifolding',
					'scstemmer',
				],
			];

			// In Serbian text_search is just a copy of text
			$config['analyzer']['text_search'] = $config['analyzer']['text'];
			break;
		case 'swedish':
			// Add asciifolding_preserve to filters
			// See https://www.mediawiki.org/wiki/User:TJones_(WMF)/T160562
			$config[ 'analyzer' ][ 'lowercase_keyword' ][ 'filter' ][] = 'asciifolding_preserve';

			// Unpack built-in swedish analyzer to add asciifolding_preserve
			$config['filter']['swedish_stop'] = [
				'type' => 'stop',
				'stopwords' => '_swedish_',
			];
			$config['filter']['swedish_stemmer'] = [
				'type' => 'stemmer',
				'language' => 'swedish',
			];

			$config['analyzer']['text'] = [
				'type' => 'custom',
				'tokenizer' => 'standard',
				'filter' => [
					'lowercase',
					'swedish_stop',
					'swedish_stemmer',
					'asciifolding_preserve',
				],
			];

			// In Swedish text_search is just a copy of text
			$config['analyzer']['text_search'] = $config['analyzer']['text'];
			break;
		case 'turkish':
			$config[ 'filter' ][ 'lowercase' ][ 'language' ] = 'turkish';
			break;
		}

		// replace lowercase filters with icu_normalizer filter
		if ( $this->icu ) {
			foreach ( $config[ 'analyzer' ] as &$analyzer ) {
				if ( !isset( $analyzer[ 'filter'  ] ) ) {
					continue;
				}
				$analyzer[ 'filter' ] = array_map( function ( $filter ) {
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
		foreach ( $config['analyzer'] as $name => &$value ) {
			if ( isset( $value['type'] ) && $value['type'] != 'custom' ) {
				continue;
			}
			if ( !isset( $value['filter'] ) ) {
				continue;
			}
			$ascii_idx = array_search( 'asciifolding_preserve', $value['filter'] );
			if ( $ascii_idx !== false ) {
				$needDedupFilter = true;
				array_splice( $value['filter'], $ascii_idx + 1, 0, [ 'dedup_asciifolding' ] );
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
	 * @param string $language Config language
	 * @return string the analyzer type
	 */
	public function getDefaultTextAnalyzerType( $language ) {
		// If we match a language exactly, use it
		if ( array_key_exists( $language, $this->elasticsearchLanguageAnalyzers ) ) {
			return $this->elasticsearchLanguageAnalyzers[ $language ];
		}

		return 'default';
	}

	/**
	 * Get list of filters that are mentioned in analyzers but not defined
	 * explicitly.
	 * @param array[] $config Full configuration array
	 * @param string[] $analyzers List of analyzers to consider.
	 * @return array List of default filters, each containing only filter type
	 */
	private function getDefaultFilters( array &$config,  array $analyzers ) {
		$defaultFilters = [];
		foreach ( $analyzers as $analyzer ) {
			if ( empty( $config['analyzer'][$analyzer]['filter'] ) ) {
				continue;
			}
			foreach ( $config['analyzer'][$analyzer]['filter'] as $filterName ) {
				if ( !isset( $config['filter'][$filterName] ) ) {
					// This is default definition for the built-in filter
					$defaultFilters[$filterName] = [ 'type' => $filterName ];
				}
			}
		}
		return $defaultFilters;
	}

	/**
	 * Check every filter in the config - if it's the same as in old config,
	 * ignore it. If it has the same name, but different content - create new filter
	 * with different name by prefixing it with language code.
	 *
	 * @param array[] $config Configuration being processed
	 * @param array[] $standardFilters Existing filters list
	 * @param array[] $defaultFilters List of default filters already mentioned in the config
	 * @param string $prefix Prefix for disambiguation
	 * @return array[] The list of filters not in the old config.
	 */
	private function resolveFilters( array &$config, array $standardFilters, array $defaultFilters, $prefix ) {
		$resultFilters = [];
		foreach ( $config['filter'] as $name => $filter ) {
			$existingFilter = null;
			if ( isset( $standardFilters[$name] ) ) {
				$existingFilter = $standardFilters[$name];
			} elseif ( isset( $defaultFilters[$name] ) ) {
				$existingFilter = $defaultFilters[$name];
			}

			if ( $existingFilter ) { // Filter with this name already exists
				if ( $existingFilter != $filter ) {
					// filter with the same name but different config - need to
					// rename by adding prefix
					$newName = $prefix . '_' . $name;
					$this->replaceFilter( $config, $name, $newName );
					$resultFilters[$newName] = $filter;
				}
			} else {
				$resultFilters[$name] = $filter;
			}
		}
		return $resultFilters;
	}

	/**
	 * Replace certain filter name in all configs with different name.
	 * @param array[] $config Configuration being processed
	 * @param string $oldName
	 * @param string $newName
	 */
	private function replaceFilter( array &$config, $oldName, $newName ) {
		foreach ( $config['analyzer'] as &$analyzer ) {
			if ( !isset( $analyzer['filter'] ) ) {
				continue;
			}
			$analyzer['filter'] = array_map( function ( $filter ) use ( $oldName, $newName ) {
				if ( $filter === $oldName ) {
					return $newName;
				}
				return $filter;
			}, $analyzer['filter'] );
		}
	}

	/**
	 * Merge per-language config into the main config.
	 * It will copy specific analyzer and all dependant filters and char_filters.
	 * @param array $config Main config
	 * @param array $langConfig Per-language config
	 * @param string $name Name for analyzer whose config we're merging
	 * @param string $prefix Prefix for this configuration
	 */
	private function mergeConfig( array &$config, array $langConfig, $name, $prefix ) {
		$analyzer = $langConfig['analyzer'][$name];
		$config['analyzer'][$prefix . '_' . $name] = $analyzer;
		if ( !empty( $analyzer['filter'] ) ) {
			// Add private filters for this analyzer
			foreach ( $analyzer['filter'] as $filter ) {
				// Copy filters that are in language config but not in the main config.
				// We would not copy the same filter into the main config since due to
				// the resolution step we know they are the same (otherwise we would have
				// renamed it).
				if ( isset( $langConfig['filter'][$filter] ) &&
					!isset( $config['filter'][$filter] ) ) {
					$config['filter'][$filter] = $langConfig['filter'][$filter];
				}
			}
		}
		if ( !empty( $analyzer['char_filter'] ) ) {
			// Add private char_filters for this analyzer
			foreach ( $analyzer['char_filter'] as $filter ) {
				// Here unlike above we do not check for $langConfig since we assume
				// language config is not broken and all char filters are namespaced
				// nicely, so if the filter is mentioned in analyzer it is also defined.
				if ( !isset( $config['char_filter'][$filter] ) ) {
					$config['char_filter'][$filter] = $langConfig['char_filter'][$filter];
				}
			}
		}
	}

	/**
	 * Create per-language configs for specific analyzers which separates and namespaces
	 * filters that are different between languages.
	 * @param array &$config Existing config, will be modified
	 * @param string[] $languages List of languages to process
	 * @param string[] $analyzers List of analyzers to process
	 */
	public function buildLanguageConfigs( array &$config, array $languages, array $analyzers ) {
		$defaultFilters = $this->getDefaultFilters( $config, $analyzers );
		foreach ( $languages as $lang ) {
			$langConfig = $this->buildConfig( $lang );
			$defaultFilters += $this->getDefaultFilters( $langConfig, $analyzers );
		}
		foreach ( $languages as $lang ) {
			$langConfig = $this->buildConfig( $lang );
			// Analyzer is: tokenizer + filter + char_filter
			// Tokenizers don't seem to be subject to customization now
			// Char filters are nicely namespaced
			// Filters are NOT - e.g. lowercase & icu_folding filters are different for different
			// languages! So we need to do some disambiguation here.
			$langConfig['filter'] = $this->resolveFilters( $langConfig, $config['filter'], $defaultFilters, $lang );
			// Merge configs
			foreach ( $analyzers as $analyzer ) {
				$this->mergeConfig( $config, $langConfig, $analyzer, $lang );
			}
		}
	}

	/**
	 * @return bool true if the icu analyzer is available.
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
	private $languagesWithIcuFolding = [
		'el' => true,
		'en' => true,
		'en-ca' => true,
		'en-gb' => true,
		'simple' => true,
		'fr' => true,
		'he' => true,
		'sv' => true,
		'sr' => true,
	];

	/**
	 * @var bool[] indexed by language code, languages where ICU tokenization
	 * can be enabled by default
	 */
	private $languagesWithIcuTokenization = [
		"bo" => true,
		"dz" => true,
		"gan" => true,
		"ja" => true,
		"km" => true,
		"lo" => true,
		"my" => true,
		"th" => true,
		"wuu" => true,
		"zh" => true,
		"lzh" => true, // zh-classical
		"zh-classical" => true, // deprecated code for lzh
		"yue" => true, // zh-yue
		"zh-yue" => true, // deprecated code for yue
		// This list below are languages that may use use mixed scripts
		"bug" => true,
		"cdo" => true,
		"cr" => true,
		"hak" => true,
		"jv" => true,
		"nan" => true, // zh-min-nan
		"zh-min-nan" => true, // deprecated code for nan
	];

	/**
	 * @var array[]
	 */
	private $elasticsearchLanguageAnalyzersFromPlugins = [
		// multiple plugin requirement can be comma separated

		// For Polish, see https://www.mediawiki.org/wiki/User:TJones_(WMF)/T154517
		// For Ukrainian, see https://www.mediawiki.org/wiki/User:TJones_(WMF)/T160106
		// For Chinese, see https://www.mediawiki.org/wiki/User:TJones_(WMF)/T158203
		// For Hebrew, see https://www.mediawiki.org/wiki/User:TJones_(WMF)/T162741

		'analysis-stempel' => [ 'pl' => 'polish' ],
		'analysis-kuromoji' => [ 'ja' => 'japanese' ],
		'analysis-stconvert,analysis-smartcn' => [ 'zh' => 'chinese' ],
		'analysis-hebrew' => [ 'he' => 'hebrew' ],
		'analysis-ukrainian' => [ 'uk' => 'ukrainian' ],
		'extra-analysis' => [ 'sr' => 'serbian' ],
	];
}
