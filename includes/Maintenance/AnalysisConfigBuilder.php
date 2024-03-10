<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\CirrusSearch;
use CirrusSearch\CirrusSearchHookRunner;
use CirrusSearch\Profile\SearchProfileService;
use CirrusSearch\SearchConfig;
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
	 * incompatible.
	 *
	 * You may also need to increment MetaStoreIndex::METASTORE_VERSION
	 * manually as well.
	 */
	public const VERSION = '0.12';

	/**
	 * Maximum number of characters allowed in keyword terms.
	 */
	private const KEYWORD_IGNORE_ABOVE = 5000;

	/**
	 * Temporary magic value to prevent enabling ICU tokenizer in specific analyzers
	 */
	private const STANDARD_TOKENIZER_ONLY = 'std_only';

	/**
	 * @var bool is the icu plugin available?
	 */
	private $icu;

	/**
	 * @var bool is the textify plugin available?
	 */
	private $textify;

	/**
	 * @var string which ICU tokenizer should be used
	 */
	private $icu_tokenizer = 'icu_tokenizer';

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
	 * @var CirrusSearchHookRunner
	 */
	private $cirrusSearchHookRunner;

	/**
	 * @var GlobalCustomFilter[]
	 */
	public $globalCustomFilters;

	/**
	 * @param string $langCode The language code to build config for
	 * @param string[] $plugins list of plugins installed in Elasticsearch
	 * @param SearchConfig|null $config
	 * @param CirrusSearchHookRunner|null $cirrusSearchHookRunner
	 */
	public function __construct(
		$langCode,
		array $plugins,
		SearchConfig $config = null,
		CirrusSearchHookRunner $cirrusSearchHookRunner = null
	) {
		$this->globalCustomFilters = $this->buildGlobalCustomFilters();

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
				$this->elasticsearchLanguageAnalyzers =
					array_merge( $this->elasticsearchLanguageAnalyzers, $extra );
			}
		}
		$this->icu = in_array( 'analysis-icu', $plugins );
		$this->textify = in_array( 'extra-analysis-textify', $plugins );
		if ( $this->isTextifyAvailable() ) {
			// icu_token_repair can only work with the textify icu_tokenizer clone
			$this->icu_tokenizer = 'textify_icu_tokenizer';
		}
		$config ??= MediaWikiServices::getInstance()->getConfigFactory()
			->makeConfig( 'CirrusSearch' );
		$similarity = $config->getProfileService()->loadProfile( SearchProfileService::SIMILARITY );
		if ( !array_key_exists( 'similarity', $similarity ) ) {
			$similarity['similarity'] = [];
		}
		$this->cirrusSearchHookRunner = $cirrusSearchHookRunner ?: new CirrusSearchHookRunner(
			MediaWikiServices::getInstance()->getHookContainer() );
		$this->cirrusSearchHookRunner->onCirrusSearchSimilarityConfig( $similarity['similarity'] );
		$this->similarity = $similarity;

		$this->config = $config;
	}

	/**
	 * Determine if ascii folding should be used
	 * @param string $language Config language
	 * @return bool true if icu folding should be enabled
	 */
	public function shouldActivateIcuFolding( $language ) {
		if ( !$this->isIcuAvailable() || !in_array( 'extra', $this->plugins ) ) {
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
			return $this->languagesWithIcuFolding[$language] ?? false;
		default:
			return false;
		}
	}

	/**
	 * Determine if the icu_tokenizer can replace the standard tokenizer for this language
	 * @param string $language Config language
	 * @return bool
	 */
	public function shouldActivateIcuTokenization( $language ) {
		if ( !$this->isIcuAvailable() && !$this->isTextifyAvailable() ) {
			// requires the icu or textify plugin
			return false;
		}
		$in_config = $this->config->get( 'CirrusSearchUseIcuTokenizer' );
		switch ( $in_config ) {
		case 'yes':
			return true;
		case 'no':
			return false;
		case 'default':
			// languagesWithIcuTokenization[] gives absolute answers for specific languages.
			// If the textify plugin is available, the default is 'yes'/true because we
			// have icu_token_repair available; if not, the default is 'no'/false
			return $this->languagesWithIcuTokenization[$language] ?? $this->isTextifyAvailable();
		default:
			return false;
		}
	}

	/**
	 * Build the analysis config.
	 *
	 * @param string|null $language Config language
	 * @return array the analysis config
	 */
	public function buildConfig( $language = null ) {
		$language ??= $this->defaultLanguage;
		$config = $this->customize( $this->defaults( $language ), $language );
		$this->cirrusSearchHookRunner->onCirrusSearchAnalysisConfig( $config, $this );
		if ( $this->shouldActivateIcuTokenization( $language ) ) {
			$config = $this->enableICUTokenizer( $config );
		}

		// must follow enableICUTokenizer()
		$config = $this->enableGlobalCustomFilters( $config, $language );

		if ( $this->shouldActivateIcuFolding( $language ) ) {
			$config = $this->enableICUFolding( $config, $language );
		}
		$config = $this->fixAsciiFolding( $config );
		$config = $this->standardTokenizerOnlyCleanup( $config );
		if ( !$this->isTextifyAvailable() ) {
			$config = $this->disableLimitedMappings( $config );
		}

		return $config;
	}

	/**
	 * @return array|null the similarity config
	 */
	public function buildSimilarityConfig() {
		return $this->similarity['similarity'] ?? null;
	}

	/**
	 * replace the standard tokenizer with icu_tokenizer
	 * @param mixed[] $config
	 * @return mixed[] update config
	 */
	public function enableICUTokenizer( array $config ) {
		foreach ( $config[ 'analyzer' ] as $name => &$value ) {
			if ( isset( $value[ 'type' ] ) && $value[ 'type' ] != 'custom' ) {
				continue;
			}
			if ( isset( $value[ 'tokenizer' ] ) && $value[ 'tokenizer' ] === 'standard' ) {
				$value[ 'tokenizer' ] = $this->icu_tokenizer;
			}
		}
		return $config;
	}

	/**
	 * replace STANDARD_TOKENIZER_ONLY with the actual standard tokenizer
	 * @param mixed[] $config
	 * @return mixed[] update config
	 */
	public function standardTokenizerOnlyCleanup( array $config ) {
		foreach ( $config[ 'analyzer' ] as $name => &$value ) {
			if ( isset( $value[ 'type' ] ) && $value[ 'type' ] != 'custom' ) {
				continue;
			}
			if ( isset( $value[ 'tokenizer' ] ) &&
					$value[ 'tokenizer' ] === self::STANDARD_TOKENIZER_ONLY ) {
				// if we blocked upgrades/changes to the standard tokenizer,
				// replace the magic value with the actual standard tokenizer
				$value[ 'tokenizer' ] = 'standard';
			}
		}
		return $config;
	}

	/**
	 * replace limited_mappings with mappings if limited_mapping is unavailable
	 * @param mixed[] $config
	 * @return mixed[] update config
	 */
	public function disableLimitedMappings( array $config ) {
		foreach ( $config[ 'char_filter' ] as $name => &$value ) {
			if ( !isset( $value[ 'type' ] ) || $value[ 'type' ] != 'limited_mapping' ) {
				continue;
			}
			$value[ 'type' ] = 'mapping';
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
		if ( $unicodeSetFilter !== null ) {
			$filter[ 'unicodeSetFilter' ] = $unicodeSetFilter;
		}
		$config[ 'filter' ][ 'icu_folding' ] = $filter;

		// Adds a simple nfkc normalizer for cases where
		// we preserve original but the lowercase filter
		// is not used before
		$config[ 'filter' ][ 'icu_nfkc_normalization' ] = [
			'type' => 'icu_normalizer',
			'name' => 'nfkc',
		];

		$newfilters = [];
		foreach ( $config[ 'analyzer' ] as $name => $value ) {
			if ( isset( $value[ 'type' ] ) && $value[ 'type' ] != 'custom' ) {
				continue;
			}
			if ( !isset( $value[ 'filter' ] ) ) {
				continue;
			}
			if ( in_array( 'asciifolding', $value[ 'filter' ] ) ) {
				$newfilters[ $name ] = $this->switchFiltersToICUFolding( $value[ 'filter' ] );
			}
			if ( in_array( 'asciifolding_preserve', $value[ 'filter' ] ) ) {
				$newfilters[ $name ] = $this->switchFiltersToICUFoldingPreserve( $value[ 'filter' ] );
			}
		}

		foreach ( $newfilters as $name => $filters ) {
			$config[ 'analyzer' ][ $name ][ 'filter' ] = $filters;
		}
		// Explicitly enable icu_folding on plain analyzers if it's not
		// already enabled
		foreach ( [ 'plain' ] as $analyzer ) {
			if ( !isset( $config[ 'analyzer' ][ $analyzer ] ) ) {
				continue;
			}
			if ( !isset( $config[ 'analyzer' ][ $analyzer ][ 'filter' ] ) ) {
				$config[ 'analyzer' ][ $analyzer ][ 'filter' ] = [];
			}
			$config[ 'analyzer' ][ $analyzer ][ 'filter' ] =
				$this->switchFiltersToICUFoldingPreserve(
					// @phan-suppress-next-line PhanTypePossiblyInvalidDimOffset
					$config[ 'analyzer' ][ $analyzer ][ 'filter' ], true );
		}

		return $config;
	}

	/**
	 * Replace occurrence of asciifolding to icu_folding
	 * @param string[] $filters
	 * @return string[] new list of filters
	 */
	private function switchFiltersToICUFolding( array $filters ) {
		array_splice( $filters, array_search( 'asciifolding', $filters ), 1,
			[ 'icu_folding', 'remove_empty' ] );
		return $filters;
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
		$newfilters[] = 'remove_empty';
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
		/* @todo: complete the default filters per language
		 *
		 * For Slovak (sk)—which has no folding configured here!—see:
		 *   https://www.mediawiki.org/wiki/User:TJones_(WMF)/T223787
		 *
		 * Exceptions are generally listed as Unicode characters for ease of
		 *   inspection. However, combining characters (such as for Thai (th))
		 *   are \u encoded to prevent problems with display or editing
		 */
		case 'bg': // T325090
			return '[^Йй]';
		case 'bs': // T192395
		case 'hr': // T192395
		case 'sh': // T192395
		case 'sr': // T183015
			return '[^ĐđŽžĆćŠšČč]';
		case 'cs': // T284578
			return '[^ÁáČčĎďÉéĚěÍíŇňÓóŘřŠšŤťÚúŮůÝýŽž]';
		case 'da': // T283366
			return '[^ÆæØøÅå]';
		case 'de': // T281379
			return '[^ÄäÖöÜüẞß]';
		case 'eo': // T202173
			return '[^ĈĉĜĝĤĥĴĵŜŝŬŭ]';
		case 'es': // T277699
			return '[^Ññ]';
		case 'et': // T332322
			return '[^ŠšŽžÕõÄäÖöÜü]';
		case 'eu': // T283366
			return '[^Ññ]';
		case 'fi': // T284578
			return '[^ÅåÄäÖö]';
		case 'gl': // T284578
			return '[^Ññ]';
		case 'hu': // T325089
			return '[^ÁáÉéÍíÓóÖöŐőÚúÜüŰű]';
		case 'ja': // T326822
			// This range includes characters that don't currently get ICU folded, in
			// order to keep the overall regex a lot simpler. The specific targets are
			// characters with dakuten and handakuten, the separate (han)dakuten
			// characters (regular and combining) and the prolonged sound mark (chōonpu).
			return '[^が-ヾ]';
		case 'lt': // T325090
			return '[^ĄąČčĘęĖėĮįŠšŲųŪūŽž]';
		case 'lv': // T325089
			return '[^ĀāČčĒēĢģĪīĶķĻļŅņŠšŪūŽž]';
		case 'nb': // T289612
		case 'nn': // T289612
			return '[^ÆæØøÅå]';
		case 'ro': // T325091
			// including s&t with cedilla because we (have to) use it internally T330893
			return '[^ĂăÂâÎîȘșȚțŞşŢţ]';
		case 'ru':
			return '[^Йй]';
		case 'sv': // T160562
			return '[^ÅåÄäÖö]';
		case 'th': // T294147
			return '[^\u0E47-\u0E4E]';
		case 'tr': // T329762
			// (I and i aren't strictly necessary but they keep the Turkish upper/lower
			// pairs Iı & İi together and makes it clear both are intended.)
			return '[^ÇçĞğIıİiÖöŞşÜü]';
		default:
			return null;
		}
	}

	/**
	 * Return the list of chars to exclude from ICU normalization
	 * @param string $language Config language
	 * @return null|string
	 */
	protected function getICUNormSetFilter( $language ) {
		if ( $this->config->get( 'CirrusSearchICUNormalizationUnicodeSetFilter' ) !== null ) {
			return $this->config->get( 'CirrusSearchICUNormalizationUnicodeSetFilter' );
		}
		switch ( $language ) {
		/* For German (de), see T281379
		 */
		case 'de':
			return '[^ẞß]'; // Capital ẞ is lowercased to ß by german_charfilter
							// lowercase ß is normalized to ss by german_normalization
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
					'type' => $this->getDefaultTextAnalyzerType( $language ),
				],
				// text_search is not configured here because it will be copied from text
				'plain' => [
					// Surprisingly, the Lucene docs claim this works for
					// Chinese, Japanese, and Thai as well.
					// The difference between this and the 'standard'
					// analyzer is the lack of english stop words.
					'type' => 'custom',
					'char_filter' => [ 'nnbsp_norm', 'word_break_helper' ],
					'tokenizer' => 'standard',
					'filter' => [ 'lowercase' ],
				],
				'plain_search' => [
					// In accent squashing languages this will not contain accent
					// squashing to allow searches with accents to only find accents
					// and searches without accents to find both.
					'type' => 'custom',
					'char_filter' => [ 'nnbsp_norm', 'word_break_helper' ],
					'tokenizer' => 'standard',
					'filter' => [ 'lowercase' ],
				],
				// Used by ShortTextIndexField
				'short_text' => [
					'type' => 'custom',
					'tokenizer' => 'whitespace',
					'filter' => [ 'lowercase', 'aggressive_splitting', 'asciifolding_preserve' ],
				],
				'short_text_search' => [
					'type' => 'custom',
					'tokenizer' => 'whitespace',
					'filter' => [ 'lowercase', 'aggressive_splitting' ],
				],
				'source_text_plain' => [
					'type' => 'custom',
					'char_filter' => [ 'word_break_helper_source_text' ],
					'tokenizer' => 'standard',
					'filter' => [ 'lowercase' ],
				],
				'source_text_plain_search' => [
					'type' => 'custom',
					'char_filter' => [ 'word_break_helper_source_text' ],
					'tokenizer' => 'standard',
					'filter' => [ 'lowercase' ],
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
					'char_filter' => [ 'near_space_flattener' ],
					'tokenizer' => 'no_splitting',
					'filter' => [ 'lowercase' ],
				],
				'near_match_asciifolding' => [
					'type' => 'custom',
					'char_filter' => [ 'near_space_flattener' ],
					'tokenizer' => 'no_splitting',
					'filter' => [ 'truncate_keyword', 'lowercase', 'asciifolding' ],
				],
				'prefix' => [
					'type' => 'custom',
					'char_filter' => [ 'near_space_flattener' ],
					'tokenizer' => 'prefix',
					'filter' => [ 'lowercase' ],
				],
				'prefix_asciifolding' => [
					'type' => 'custom',
					'char_filter' => [ 'near_space_flattener' ],
					'tokenizer' => 'prefix',
					'filter' => [ 'lowercase', 'asciifolding' ],
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
					'type' => 'word_delimiter_graph',
					'stem_english_possessive' => false,
					'preserve_original' => false
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
				'remove_empty' => [
					'type' => 'length',
					'min' => 1,
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
					'type' => 'limited_mapping',
					'mappings' => [
						"'=>\u0020", // Useful for finding names
						'\u2019=>\u0020', // Unicode right single quote
						'\u02BC=>\u0020', // Unicode modifier letter apostrophe
						'_=>\u0020', // MediaWiki loves _ and people are used to it but it usually means space
						'-=>\u0020', // Useful for finding hyphenated names unhyphenated
					],
				],
				// map narrow no-break space to plain space to compensate for ES6.x+
				// analyzers generally not doing so
				'nnbsp_norm' => [
					'type' => 'limited_mapping',
					'mappings' => [
						'\u202F=>\u0020',
					],
				],
				// Add a space between lowercase letter {Ll} and uppercase {Lu} or
				// titlecase {Lt} letter, allowing for optional combining marks {M}
				// or invisibles {Cf}. This is expensive, so use camelCase_splitter
				// in extra-analysis-textify instead, if available (T219108/T346051)
				'regex_camelCase' => [
					'type' => 'pattern_replace',
					'pattern' => '(\\p{Ll}[\\p{M}\\p{Cf}]*)([\\p{Lu}\\p{Lt}])',
					'replacement' => '$1 $2'
				],
				// Replace period (regular or fullwidth) between [non-letter +
				// letter] and [letter + non-letter]. This slow, and also only
				// handles the simplest case. Use acronym_fixer in
				// extra-analysis-textify instead, if available (T170625/T346051)
				'regex_acronym_fixer' => [
					'type' => 'pattern_replace',
					'pattern' => '(?<=(?:^|\\P{L})\\p{L})[.．](\\p{L})(?=\\P{L}|$)',
					'replacement' => '$1'
				],
				// combine universally-applied mappings into one mapping to save on the
				// overhead of calling multiple mappings
				'globo_norm' => [
					'type' => 'limited_mapping',
					'mappings' => [
						// map lots of apostrophe-like characters to apostrophe (T315118);
						// formerly apostrophe_norm
						"`=>'", // grave accent
						"´=>'", // acute accent
						"ʹ=>'", // modifier letter prime
						"ʻ=>'", // modifier letter turned comma
						"ʼ=>'", // modifier letter apostrophe
						"ʽ=>'", // modifier letter reversed comma
						"ʾ=>'", // modifier letter right half ring
						"ʿ=>'", // modifier letter left half ring
						"ˋ=>'", // modifier letter grave accent
						"՚=>'", // Armenian apostrophe
						"\u05F3=>'", // Hebrew punctuation geresh
						"‘=>'", // left single quotation mark
						"’=>'", // right single quotation mark
						"‛=>'", // single high-reversed-9 quotation mark
						"′=>'", // prime
						"‵=>'", // reversed prime
						"ꞌ=>'", // Latin small letter saltillo
						"＇=>'", // fullwidth apostrophe
						"｀=>'", // fullwidth grave accent
						// map narrow no-break space to plain space to compensate for ES6.x+
						// analyzers generally not doing so; copied from nnbsp_norm, which
						// is still needed elsewhere
						'\u202F=>\u0020',
						// Delete primary and secondary stress markers, which are
						// inconsistently used across phonetic transcriptions
						"ˈ=>", // modifier letter vertical line
						"ˌ=>", // modifier letter low vertical line
						// Delete Arabic tatweel (ـ) (used largely for cosmetic purposes)
						"\u0640=>", // tatweel
						// Convert Arabic thousand separator and Arabic comma to comma for
						// more consistent number parsing
						"٬=>,", // Arabic thousands separator
						"،=>,", // Arabic comma
						// delete Armenian emphasis marks, exclamation marks, and question
						// marks, since they modify words rather than follow them.
						"՛=>", // Armenian emphasis mark
						"՜=>", // Armenian exclamation mark
						"՞=>", // Armenian question mark
						// micro sign to mu, to prevent some unneeded ICU tokenizer splits
						// icu_normalize does this, too.. just later
						"µ=>μ",
					],
				],
				// Converts things that don't always count as word breaks into spaces
				// which (almost) always count as word breaks (e.g., the Nori and SmartCN
				// tokenizers do not always count spaces as word breaks!)
				'word_break_helper' => [
					'type' => 'limited_mapping',
					'mappings' => [
						'_=>\u0020',
						':=>\u0020',
						// These are more useful for code:
						'.=>\u0020',
						'(=>\u0020',
						')=>\u0020',
						// fullwidth variants
						'．=>\u0020',
						'＿=>\u0020',
						'：=>\u0020',
						// middle dot
						'·=>\u0020',
					],
				],
				'word_break_helper_source_text' => [
					'type' => 'limited_mapping',
					'mappings' => [
						'_=>\u0020',
						// These are more useful for code:
						'.=>\u0020',
						'(=>\u0020',
						')=>\u0020',
						':=>\u0020', // T145023
					],
				],
				'dotted_I_fix' => [
					// A common regression caused by unpacking is that İ is no longer
					// treated correctly, so specify the mapping just once and re-use
					// in analyzer/text/char_filter as needed.
					'type' => 'limited_mapping',
					'mappings' => [
						'İ=>I',
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
		if ( $this->isTextifyAvailable() && $this->shouldActivateIcuTokenization( $language ) ) {
			$defaults[ 'filter' ][ 'icutokrep_no_camel_split' ] = [
				'type' => 'icu_token_repair',
				'keep_camel_split' => false
			];
		}
		if ( $this->isIcuAvailable() ) {
			$defaults[ 'filter' ][ 'icu_normalizer' ] = [
				'type' => 'icu_normalizer',
				'name' => 'nfkc_cf',
			];
			$unicodeSetFilter = $this->getICUNormSetFilter( $language );
			if ( $unicodeSetFilter !== null ) {
				$defaults[ 'filter' ][ 'icu_normalizer' ][ 'unicodeSetFilter' ] = $unicodeSetFilter;
			}
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
		$langName = $this->getDefaultTextAnalyzerType( $language );
		switch ( $langName ) {
		// Please add languages in alphabetical order.

		// usual unpacked languages
		case 'basque':    // Unpack Basque analyzer T283366
		case 'brazilian': // Unpack Brazilian analyzer T325092
		case 'bulgarian': // Unpack Bulgarian analyzer T325090
		case 'czech':     // Unpack Czech analyzer T284578
		case 'danish':    // Unpack Danish analyzer T283366
		case 'estonian':  // Unpack Estonian analyzer T332322
		case 'finnish':   // Unpack Finnish analyzer T284578
		case 'galician':  // Unpack Galician analyzer T284578
		case 'hungarian': // Unpack Hungarian analyzer T325089
		case 'latvian':   // Unpack Latvian analyzer T325089
		case 'lithuanian': // Unpack Lithuanian analyzer T325090
		case 'norwegian': // Unpack Norwegian analyzer T289612
			$config = ( new AnalyzerBuilder( $langName ) )->
				withUnpackedAnalyzer()->
				build( $config );
			break;

		// usual unpacked languages, with "light" variant stemmer
		case 'portuguese':  // Unpack Portuguese analyzer T281379
		case 'spanish':     // Unpack Spanish analyzer T277699
			$config = ( new AnalyzerBuilder( $langName ) )->
				withUnpackedAnalyzer()->
				withLightStemmer()->
				build( $config );
			break;

		// customized languages
		case 'arabic':
		case 'arabic-egyptian':
		case 'arabic-moroccan':
			// Unpack Arabic analyzer T294147
			$arBuilder = ( new AnalyzerBuilder( 'arabic' ) )->
				withUnpackedAnalyzer()->
				withDecimalDigit()->
				insertFiltersBefore( 'arabic_stemmer', [ 'arabic_normalization' ] );

			// load extra stopwords for Arabic varieties
			if ( $langName == 'arabic-egyptian' || $langName == 'arabic-moroccan' ) {
				$arStopwords = require __DIR__ . '/AnalysisLanguageData/arabicStopwords.php';
				$arBuilder->withExtraStop( $arStopwords, 'arz_ary_stop', 'arabic_stop' );
			}

			$config = $arBuilder->build( $config );
			break;
		case 'armenian':  // Unpack Armenian analyzer T325089
			// char map: Armenian uses ․ ("one-dot leader") about 10% as often as . (period)
			// stopwords նաև & և get normalized to նաեւ & եւ, so pick those up, too.
			$config = ( new AnalyzerBuilder( $langName ) )->
				withUnpackedAnalyzer()->
				withLimitedCharMap( [ '․=>.' ] )->
				withExtraStop( [ 'նաեւ', 'եւ' ], 'armenian_norm_stop', 'armenian_stop' )->
				build( $config );
			break;
		case 'bengali': // Unpack Bengali analyzer T294067
			$config = ( new AnalyzerBuilder( $langName ) )->
				withUnpackedAnalyzer()->
				withDecimalDigit()->
				insertFiltersBefore( 'bengali_stop', [ 'indic_normalization' ] )->
				build( $config );
			break;
		case 'bosnian':
		case 'croatian':
		case 'serbian':
		case 'serbo-croatian':
			// Unpack default analyzer to add Serbian stemming and custom folding
			// See https://www.mediawiki.org/wiki/User:TJones_(WMF)/T183015
			// and https://www.mediawiki.org/wiki/User:TJones_(WMF)/T192395
			$config = ( new AnalyzerBuilder( $langName ) )->
				withFilters( [ 'lowercase', 'asciifolding', 'serbian_stemmer' ] )->
				build( $config );
			break;
		case 'catalan':
			// Unpack Catalan analyzer T283366
			$config = ( new AnalyzerBuilder( $langName ) )->
				withUnpackedAnalyzer()->
				withElision( [ 'd', 'l', 'm', 'n', 's', 't' ] )->
				build( $config );
			break;
		case 'chinese':
			// See https://www.mediawiki.org/wiki/User:TJones_(WMF)/T158203
			$config[ 'char_filter' ][ 'tsconvert' ] = [
				'type' => 'stconvert',
				'delimiter' => '#',
				'keep_both' => false,
				'convert_type' => 't2s',
			];

			// char map: hack for STConvert errors (still present as of July 2023)
			// see https://github.com/medcl/elasticsearch-analysis-stconvert/issues/13
			// stop: SmartCN converts lots of punctuation to ',' but we don't want to index it
			$config = ( new AnalyzerBuilder( $langName ) )->
				withCharMap( [ '\u606d\u5f18=>\u606d \u5f18', '\u5138=>\u3469' ], 'stconvertfix' )->
				withCharFilters( [ 'stconvertfix', 'tsconvert' ] )->
				withTokenizer( 'smartcn_tokenizer' )->
				withStop( [ ',' ], 'smartcn_stop' )->
				withFilters( [ 'smartcn_stop', 'lowercase' ] )->
				build( $config );

			$config[ 'analyzer' ][ 'plain' ][ 'filter' ] = [ 'smartcn_stop', 'lowercase' ];
			$config[ 'analyzer' ][ 'plain_search' ][ 'filter' ] =
				$config[ 'analyzer' ][ 'plain' ][ 'filter' ];
			break;
		case 'cjk':
			// Unpack CJK analyzer T326822
			// map (han)dakuten to combining forms or icu_normalizer will add spaces
			$dakutenMap = [ '゛=>\u3099', '゜=>\u309a' ];

			// cjk_bigram negates the benefits of the icu_tokenizer for CJK text. The
			// icu_tokenizer also has a few bad side effects, so don't use it for cjk.
			// Default cjk stop words are almost the same as _english_ (add s & t; drop
			// an). Stop words are searchable via 'plain' anyway, so just use _english_
			$config = ( new AnalyzerBuilder( 'cjk' ) )->
				withUnpackedAnalyzer()->
				withLimitedCharMap( $dakutenMap )->
				withTokenizer( self::STANDARD_TOKENIZER_ONLY )->
				withStop( '_english_' )->
				omitStemmer()->
				insertFiltersBefore( 'lowercase', [ 'cjk_width' ] )->
				insertFiltersBefore( 'cjk_stop', [ 'cjk_bigram' ] )->
				build( $config );
			break;
		case 'dutch':
			// Unpack Dutch analyzer T281379
			$nlOverride = [ // these are in the default Dutch analyzer
				'fiets=>fiets',
				'bromfiets=>bromfiets',
				'ei=>eier',
				'kind=>kinder'
			];
			$config = ( new AnalyzerBuilder( $langName ) )->
				withUnpackedAnalyzer()->
				withStemmerOverride( $nlOverride )->
				build( $config );
			break;
		case 'english':
			// Map hiragana (\u3041-\u3096) to katakana (\u30a1-\u30f6), currently only for English
			// See https://www.mediawiki.org/wiki/User:TJones_(WMF)/T176197
			$hkmap = [];
			for ( $i = 0x3041; $i <= 0x3096; $i++ ) {
			  $hkmap[] = sprintf( '\\u%04x=>\\u%04x', $i, $i + 0x60 );
			}

			// Replace English analyzer with a rebuilt copy with asciifolding inserted before stemming
			// See https://www.mediawiki.org/wiki/User:TJones_(WMF)/T142037
			$config = ( new AnalyzerBuilder( $langName ) )->
				withLimitedCharMap( $hkmap, 'kana_map' )->
				withCharFilters( [ 'kana_map' ] )->
				withExtraStemmer( 'possessive_english' )->
				withStemmerOverride( 'guidelines => guideline', 'custom_stem' )->
				withFilters( [ 'possessive_english', 'lowercase', 'stop', 'asciifolding', 'kstem',
					'custom_stem' ] )->
				build( $config );

			// Add asciifolding_preserve to the plain analyzer as well (but not plain_search)
			$config[ 'analyzer' ][ 'plain' ][ 'filter' ][] = 'asciifolding_preserve';
			// Add asciifolding_preserve filters
			$config[ 'analyzer' ][ 'lowercase_keyword' ][ 'filter' ][] = 'asciifolding_preserve';
			break;
		case 'esperanto':
			// See https://www.mediawiki.org/wiki/User:TJones_(WMF)/T202173
			$config = ( new AnalyzerBuilder( $langName ) )->
				withFilters( [ 'lowercase', 'asciifolding', 'esperanto_stemmer' ] )->
				build( $config );
			break;
		case 'french':
			// Add asciifolding_preserve to filters
			// See https://www.mediawiki.org/wiki/User:TJones_(WMF)/T142620
			$config[ 'analyzer' ][ 'lowercase_keyword' ][ 'filter' ][] = 'asciifolding_preserve';

			$config = ( new AnalyzerBuilder( $langName ) )->
				withUnpackedAnalyzer()->
				withLimitedCharMap( [ '\u02BC=>\u0027' ] )->
				withElision( [ 'l', 'm', 't', 'qu', 'n', 's', 'j', 'd', 'c',
								'jusqu', 'quoiqu', 'lorsqu', 'puisqu' ] )->
				withLightStemmer()->
				withAsciifoldingPreserve()->
				build( $config );
			break;
		case 'german':
			// Unpack German analyzer T281379
			// char map: We have to explicitly map capital ẞ to lowercase ß
			$config = ( new AnalyzerBuilder( $langName ) )->
				withUnpackedAnalyzer()->
				withLimitedCharMap( [ 'ẞ=>ß' ] )->
				withLightStemmer()->
				insertFiltersBefore( 'german_stemmer', [ 'german_normalization' ] )->
				build( $config );

			$config[ 'analyzer' ][ 'plain' ][ 'char_filter' ][] = 'german_charfilter';
			$config[ 'analyzer' ][ 'plain_search' ][ 'char_filter' ][] = 'german_charfilter';
			break;
		case 'greek':
			$config = ( new AnalyzerBuilder( $langName ) )->
				withUnpackedAnalyzer()->
				omitDottedI()->
				omitAsciifolding()->
				withLangLowercase()->
				withRemoveEmpty()->
				build( $config );
			break;
		case 'hebrew':
			$config = ( new AnalyzerBuilder( $langName ) )->
				withTokenizer( 'hebrew' )->
				withFilters( [ 'niqqud', 'hebrew_lemmatizer', 'remove_duplicates', 'lowercase',
					'asciifolding' ] )->
				build( $config );
			break;
		case 'hindi':
			// Unpack Hindi analyzer T289612
			$config = ( new AnalyzerBuilder( $langName ) )->
				withUnpackedAnalyzer()->
				withDecimalDigit()->
				insertFiltersBefore( 'hindi_stop',
					[ 'indic_normalization', 'hindi_normalization' ] )->
				build( $config );
			break;
		case 'indonesian':
		case 'malay':
			// See https://www.mediawiki.org/wiki/User:TJones_(WMF)/T196780
			$config = ( new AnalyzerBuilder( 'indonesian' ) )->
				withUnpackedAnalyzer()->
				omitAsciifolding()->
				build( $config );
			break;
		case 'irish':
			$gaCharMap = [ 'ḃ=>bh', 'ċ=>ch', 'ḋ=>dh', 'ḟ=>fh', 'ġ=>gh', 'ṁ=>mh', 'ṗ=>ph',
				  'ṡ=>sh', 'ẛ=>sh', 'ṫ=>th', 'Ḃ=>BH', 'Ċ=>CH', 'Ḋ=>DH', 'Ḟ=>FH', 'Ġ=>GH',
				  'Ṁ=>MH', 'Ṗ=>PH', 'Ṡ=>SH', 'Ṫ=>TH' ];
			$gaHyphenStop = [ 'h', 'n', 't', 'b', 'bh', 'g', 'm' ]; // Add b, bh, g, m for camelCase cleanup
			// Unpack Irish analyzer T289612
			// See also https://www.mediawiki.org/wiki/User:TJones_(WMF)/T217602
			$config = ( new AnalyzerBuilder( $langName ) )->
				withUnpackedAnalyzer()->
				withCharMap( $gaCharMap )->
				omitDottedI()->
				withExtraStop( $gaHyphenStop, 'irish_hyphenation', 'irish_elision', true )->
				withElision( [ 'd', 'm', 'b' ] )->
				withLangLowercase()->
				build( $config );
			break;
		case 'italian':
			// Replace the default Italian analyzer with a rebuilt copy with additional filters
			$itElision = [ 'c', 'l', 'all', 'dall', 'dell', 'nell', 'sull', 'coll', 'pell',
				'gl', 'agl', 'dagl', 'degl', 'negl', 'sugl', 'un', 'm', 't', 's', 'v', 'd' ];
			$config = ( new AnalyzerBuilder( $langName ) )->
				withUnpackedAnalyzer()->
				omitDottedI()->
				withElision( $itElision )->
				withLightStemmer()->
				build( $config );

			// Add asciifolding_preserve to the plain analyzer as well (but not plain_search)
			$config[ 'analyzer' ][ 'plain' ][ 'filter' ][] = 'asciifolding_preserve';
			// Add asciifolding_preserve to filters
			$config[ 'analyzer' ][ 'lowercase_keyword' ][ 'filter' ][] = 'asciifolding_preserve';
			break;
		case 'japanese':
			// See https://www.mediawiki.org/wiki/User:TJones_(WMF)/T166731
			// pre-convert fullwidth numbers because Kuromoji tokenizer treats them weirdly
			$config = ( new AnalyzerBuilder( $langName ) )->
				withNumberCharFilter( 0xff10, 'fullwidthnumfix' )->
				withCharFilters( [ 'fullwidthnumfix' ] )->
				withTokenizer( 'kuromoji_tokenizer' )->
				withFilters( [ 'kuromoji_baseform', 'cjk_width', 'ja_stop', 'kuromoji_stemmer',
					'lowercase' ] )->
				build( $config );
			break;
		case 'khmer':
			// See Khmer: https://www.mediawiki.org/wiki/User:TJones_(WMF)/T185721
			$config = ( new AnalyzerBuilder( $langName ) )->
				withNumberCharFilter( 0x17e0 )->
				withCharFilters( [ 'khmer_syll_reorder', 'khmer_numbers' ] )->
				withFilters( [ 'lowercase' ] )->
				build( $config );
			break;
		case 'korean':
			// Unpack nori analyzer to add ICU normalization and custom filters
			// See https://www.mediawiki.org/wiki/User:TJones_(WMF)/T206874

			// Nori-specific character filter
			$noriMap = [
				'\u00B7=>\u0020', // convert middle dot to space
				'\u318D=>\u0020', // arae-a to space
				'\u00AD=>', // remove soft hyphens
				'\u200C=>', // remove zero-width non-joiners
			];

			// Nori-specific pattern_replace to strip combining diacritics
			$config[ 'char_filter' ][ 'nori_combo_filter' ] =
				AnalyzerBuilder::patternFilter( '[\\u0300-\\u0331]' );

			// 'mixed' mode keeps the original token plus the compound parts
			// the default is 'discard' which only keeps the parts
			$config[ 'tokenizer' ][ 'nori_tok' ] = [
				'type' => 'nori_tokenizer',
				'decompound_mode' => 'mixed',
			];

			// Nori-specific part of speech filter (add 'VCP', 'VCN', 'VX' to default)
			$config[ 'filter' ][ 'nori_posfilter' ] = [
				'type' => 'nori_part_of_speech',
				'stoptags' => [ 'E', 'IC', 'J', 'MAG', 'MAJ', 'MM', 'SP', 'SSC', 'SSO', 'SC',
					'SE', 'XPN', 'XSA', 'XSN', 'XSV', 'UNA', 'NA', 'VSV', 'VCP', 'VCN', 'VX' ],
			];

			$config = ( new AnalyzerBuilder( $langName ) )->
				withLimitedCharMap( $noriMap, 'nori_charfilter' )->
				withCharFilters( [ 'dotted_I_fix', 'nori_charfilter', 'nori_combo_filter' ] )->
				withTokenizer( 'nori_tok' )->
				withFilters( [ 'nori_posfilter', 'nori_readingform', 'lowercase', 'remove_empty' ] )->
				build( $config );
			break;
		case 'mirandese':
			// Unpack default analyzer to add Mirandese-specific elision and stop words
			// See phab ticket T194941
			$mwlStopwords = require __DIR__ . '/AnalysisLanguageData/mirandeseStopwords.php';
			$config = ( new AnalyzerBuilder( $langName ) )->
				withElision( [ 'l', 'd', 'qu' ] )->
				withStop( $mwlStopwords )->
				withFilters( [ 'lowercase', 'mirandese_elision', 'mirandese_stop' ] )->
				build( $config );
			break;
		case 'persian': // Unpack Persian analyzer T325090
			$config = ( new AnalyzerBuilder( $langName ) )->
				withUnpackedAnalyzer()->
				withLimitedCharMap( [ '\u200C=>\u0020' ], 'zero_width_spaces' )->
				withDecimalDigit()->
				omitStemmer()->
				insertFiltersBefore( 'persian_stop',
					[ 'arabic_normalization', 'persian_normalization' ] )->
				build( $config );
			break;
		case 'polish':
			// these are real stop words for Polish
			$plStopwords = require __DIR__ . '/AnalysisLanguageData/polishStopwords.php';

			// Stempel-specific stop words--additional unreliable stems
			$stempelStopwords = [ 'ować', 'iwać', 'obić', 'snąć', 'ywać', 'ium', 'my', 'um' ];

			// Stempel is statistical, and certain stems are really terrible, so we filter them
			// after stemming. See https://www.mediawiki.org/wiki/User:TJones_(WMF)/T186046
			$config[ 'filter' ][ 'stempel_pattern_filter' ] =
				AnalyzerBuilder::patternFilter( '^([a-zął]?[a-zćń]|..ć|\d.*ć)$' );

			$config = ( new AnalyzerBuilder( $langName ) )->
				withUnpackedAnalyzer()->
				withStop( $plStopwords )->
				omitStemmer()->
				omitAsciiFolding()->
				appendFilters( [ 'polish_stem', 'stempel_pattern_filter', 'remove_empty' ] )->
				withExtraStop( $stempelStopwords, 'stempel_stop' )->
				build( $config );
			break;
		case 'romanian':  // Unpack Romanian analyzer T325091 / T330893
			// Counterintuitively, we need to map correct s&t (with commas) to older
			// incorrect forms (with cedilla) so that the old Snowball stemmer (from before
			// comma forms were available) will work; also normalize versions with
			// combining diacritics to single characters.
			$cedillaMap = [
				'ș=>ş', 's\u0326=>ş', 's\u0327=>ş', 'ț=>ţ', 't\u0326=>ţ', 't\u0327=>ţ',
				'Ș=>Ş', 'S\u0326=>Ş', 'S\u0327=>Ş', 'Ț=>Ţ', 'T\u0326=>Ţ', 'T\u0327=>Ţ',
			];

			// Add stopword variants with modern commas instead of old cedillas so that
			// both are handled, regardless of the character mapping needed for the
			// stemmer. In the future, Lucene should update their stopwords and these will
			// be included.
			$roStopwords = require __DIR__ . '/AnalysisLanguageData/romanianStopwords.php';

			$config = ( new AnalyzerBuilder( $langName ) )->
				withUnpackedAnalyzer()->
				withCharMap( $cedillaMap )->
				withExtraStop( $roStopwords, 'ro_comma_stop', 'romanian_stemmer' )->
				build( $config );
			break;
		case 'russian':
			// unpack built-in Russian analyzer and add character filter
			// See https://www.mediawiki.org/wiki/User:TJones_(WMF)/T124592
			$ruCharMap = [
					'\u0301=>', // combining acute accent, only used to show stress T102298
					'\u0435\u0308=>\u0435', // T124592 fold ё=>е and Ё=>Е, with combining diacritic...
					'\u0415\u0308=>\u0415',
					'\u0451=>\u0435', // ... or precomposed
					'\u0401=>\u0415',
				];
			$config = ( new AnalyzerBuilder( $langName ) )->
				withUnpackedAnalyzer()->
				withCharMap( $ruCharMap )->
				build( $config );

			// add Russian character mappings to near_space_flattener, and convert it from
			// limited_mapping to mapping to handle multi-char maps
			$config[ 'char_filter' ][ 'near_space_flattener' ][ 'type' ] = 'mapping';
			array_push( $config[ 'char_filter' ][ 'near_space_flattener' ][ 'mappings' ],
				...$ruCharMap );

			// Drop acute stress marks and fold ё=>е everywhere
			// See https://www.mediawiki.org/wiki/User:TJones_(WMF)/T124592
			$config[ 'analyzer' ][ 'plain' ][ 'char_filter' ][] = 'russian_charfilter';
			$config[ 'analyzer' ][ 'plain_search' ][ 'char_filter' ][] = 'russian_charfilter';

			$config[ 'analyzer' ][ 'suggest' ][ 'char_filter' ][] = 'russian_charfilter';
			$config[ 'analyzer' ][ 'suggest_reverse' ][ 'char_filter' ][] = 'russian_charfilter';
			break;
		case 'slovak':
			// See https://www.mediawiki.org/wiki/User:TJones_(WMF)/T190815
			// and https://www.mediawiki.org/wiki/User:TJones_(WMF)/T223787
			$config = ( new AnalyzerBuilder( $langName ) )->
				withFilters( [ 'lowercase', 'slovak_stemmer', 'asciifolding' ] )->
				build( $config );
			break;
		case 'sorani':    // Unpack Sorani analyzer T325091
			$config = ( new AnalyzerBuilder( $langName ) )->
				withUnpackedAnalyzer()->
				withDecimalDigit()->
				insertFiltersBefore( 'lowercase', [ 'sorani_normalization' ] )->
				build( $config );
			break;
		case 'swedish':
			// Add asciifolding_preserve to lowercase_keyword
			// See https://www.mediawiki.org/wiki/User:TJones_(WMF)/T160562
			$config[ 'analyzer' ][ 'lowercase_keyword' ][ 'filter' ][] = 'asciifolding_preserve';

			// Unpack built-in swedish analyzer to add asciifolding_preserve
			$config = ( new AnalyzerBuilder( $langName ) )->
				withUnpackedAnalyzer()->
				omitDottedI()->
				withAsciifoldingPreserve()->
				build( $config );
			break;
		case 'thai':
			// Unpack and improve Thai analyzer: T294147
			$thCharMap = [
				'_=>\u0020', // split tokens on underscore ..
				';=>\u0020', // .. semicolon
				':=>\u0020', // .. colon
				'·=>\u0020', // .. middle dot
				'‧=>\u0020', // .. & hyphenation point
				'ฃ=>ข', // replace obsolete ฃ
				'ฅ=>ค', // replace obsolete ฅ
				'\u0e4d\u0e32=>\u0e33', // compose nikhahit + sara aa = sara am
				'\u0e4d\u0e48\u0e32=>\u0e48\u0e33', // recompose sara am split around..
				'\u0e4d\u0e49\u0e32=>\u0e49\u0e33', // .. other diacritics
				'\u0e33\u0e48=>\u0e48\u0e33', // sara am should consistently..
				'\u0e33\u0e49=>\u0e49\u0e33', // .. come after other diacritics
				'\u0E34\u0E4D=>\u0E36', // compose sara i + nikhahit = sara ue..
				'\u0E4D\u0E34=>\u0E36', // .. in either order
			];

			// instantiate basic unpacked analyzer builder, plus thai tokenizer by default
			$thBuilder = ( new AnalyzerBuilder( $langName ) )
				->withUnpackedAnalyzer()
				->withTokenizer( 'thai' );

			if ( $this->isIcuAvailable() ) {
				// ICU tokenizer is preferred in general. If it is available, replace
				// default tokenizer. Also add thai_repl_pat char filter to accommodate
				// some of its weaknesses.
				$thBuilder->withTokenizer( $this->icu_tokenizer );

				$thaiLetterPat = '[ก-๏]'; // Thai characters, except for digits.
				$config[ 'char_filter' ][ 'thai_repl_pat' ] =
					// break between any digits and Thai letters, or vice versa
					// break *Thai* tokens on periods (by making them spaces)
					// (regex look-behind is okay, but look-ahead breaks offsets)
					AnalyzerBuilder::patternFilter( "(?<=\\p{Nd})($thaiLetterPat)" .
						"|(?<=$thaiLetterPat)(\\p{Nd})" .
						"|(?<=$thaiLetterPat)\.($thaiLetterPat)",
						' $1$2$3' );
				$thBuilder->withCharFilters( [ 'thai_repl_pat' ] );

				// if icu_token_repair (in the textify plugin) is available, we need a
				// reverse number map so it doesn't rejoin split-off Arabic numbers.
				if ( $this->isTextifyAvailable() ) {
					$thBuilder->withReversedNumberCharFilter( 0x0e50 );
				}
			} else {
				// if we have to settle for the Thai tokenizer, add some additional
				// character filters to accommodate some of its weaknesses
				$thThaiTokSplits = [
					'\u200B=>', // delete zero width space
					'-=>\u0020', // split tokens on hyphen-minus ..
					'‐=>\u0020', // .. hyphen
					'–=>\u0020', // .. en dash
					'—=>\u0020', // .. em dash
					'―=>\u0020', // .. horizontal bar
					'－=>\u0020', // .. fullwidth hyphen
					'"=>\u0020', // .. & double quote
				];
				array_push( $thCharMap, ...$thThaiTokSplits );
			}

			// add in the rest of the bits that are always needed, and build
			$config = $thBuilder->withCharMap( $thCharMap )->
				withDecimalDigit()->
				omitStemmer()->
				build( $config );
			break;
		case 'turkish':
			$trAposFilter = 'apostrophe';
			if ( in_array( 'extra-analysis-turkish', $this->plugins ) ) {
				$trAposFilter = 'better_apostrophe';
			}
			$config = ( new AnalyzerBuilder( $langName ) )->
				withUnpackedAnalyzer()->
				omitDottedI()->
				withLangLowercase()->
				insertFiltersBefore( 'turkish_stop', [ $trAposFilter ] )->
				build( $config );
			break;
		case 'ukrainian-unpacked':
			$this->languagesWithIcuFolding['uk'] = true;
			$ukCharMap = [ '‘=>\'', // normalize apostrophes
						   '’=>\'',
						   '`=>\'',
						   '´=>\'',
						   'ʼ=>\'',
						   '\u0301=>', // delete combining acute and soft hyphen
						   '\u00AD=>',
						   'ґ=>г', // normalize ghe with upturn
						   'Ґ=>Г',
			];
			// lowercase twice because stopwords are case sensitive, and the stemmer
			// generates some output with uppercase initial letters, even for
			// lowercase input (usually proper names)
			$ukFilters = [ 'lowercase', 'ukrainian_stop', 'ukrainian_stemmer',
					'lowercase', 'remove_duplicates', 'asciifolding' ];
			$config = ( new AnalyzerBuilder( 'ukrainian' ) )->
				withLimitedCharMap( $ukCharMap )->
				withCharFilters( [ 'dotted_I_fix', 'ukrainian_charfilter' ] )->
				withFilters( $ukFilters )->
				build( $config );
			break;
		default:
			// do nothing--default config is already set up
			break;
		}

		// text_search is just a copy of text
		// @phan-suppress-next-line PhanTypeInvalidDimOffset
		$config[ 'analyzer' ][ 'text_search' ] = $config[ 'analyzer' ][ 'text' ];

		// replace lowercase filters with icu_normalizer filter
		if ( $this->isIcuAvailable() ) {
			foreach ( $config[ 'analyzer' ] as &$analyzer ) {
				if ( !isset( $analyzer[ 'filter'  ] ) ) {
					continue;
				}

				$tmpFilters = [];
				foreach ( $analyzer[ 'filter' ] as $filter ) {
					if ( $filter === 'lowercase' ) {
						// If lowercase filter has language-specific processing, keep it,
						// and do it before ICU normalization, particularly for Greek,
						// Irish, and Turkish
						// See https://www.mediawiki.org/wiki/User:TJones_(WMF)/T203117
						// See https://www.mediawiki.org/wiki/User:TJones_(WMF)/T217602
						if ( isset( $config[ 'filter' ][ 'lowercase' ][ 'language' ] ) ) {
							$tmpFilters[] = 'lowercase';
						}
						$tmpFilters[] = 'icu_normalizer';
					} else {
						$tmpFilters[] = $filter;
					}
				}
				$analyzer[ 'filter' ] = $tmpFilters;

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
		foreach ( $config[ 'analyzer' ] as $name => &$value ) {
			if ( isset( $value[ 'type' ] ) && $value[ 'type' ] != 'custom' ) {
				continue;
			}
			if ( !isset( $value[ 'filter' ] ) ) {
				continue;
			}
			$ascii_idx = array_search( 'asciifolding_preserve', $value[ 'filter' ] );
			if ( $ascii_idx !== false ) {
				$needDedupFilter = true;
				array_splice( $value[ 'filter' ], $ascii_idx + 1, 0, [ 'dedup_asciifolding' ] );
			}
		}
		if ( $needDedupFilter ) {
			$config[ 'filter' ][ 'dedup_asciifolding' ] = [
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
	 * @param array[] &$config Full configuration array
	 * @param string[] $analyzers List of analyzers to consider.
	 * @return array List of default filters, each containing only filter type
	 */
	private function getDefaultFilters( array &$config, array $analyzers ) {
		$defaultFilters = [];
		foreach ( $analyzers as $analyzer ) {
			if ( empty( $config[ 'analyzer' ][ $analyzer ][ 'filter' ] ) ) {
				continue;
			}
			foreach ( $config[ 'analyzer' ][ $analyzer ][ 'filter' ] as $filterName ) {
				if ( !isset( $config[ 'filter' ][ $filterName ] ) ) {
					// This is default definition for the built-in filter
					$defaultFilters[ $filterName ] = [ 'type' => $filterName ];
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
	 * @param array[] &$config Configuration being processed
	 * @param array[] $standardFilters Existing filters list
	 * @param array[] $defaultFilters List of default filters already mentioned in the config
	 * @param string $prefix Prefix for disambiguation
	 * @return array[] The list of filters not in the old config.
	 */
	private function resolveFilters( array &$config, array $standardFilters, array $defaultFilters, $prefix ) {
		$resultFilters = [];
		foreach ( $config[ 'filter' ] as $name => $filter ) {
			$existingFilter = $standardFilters[$name] ?? $defaultFilters[$name] ?? null;
			if ( $existingFilter ) { // Filter with this name already exists
				if ( $existingFilter != $filter ) {
					// filter with the same name but different config - need to
					// rename by adding prefix
					$newName = $prefix . '_' . $name;
					$this->replaceFilter( $config, $name, $newName );
					$resultFilters[ $newName ] = $filter;
				}
			} else {
				$resultFilters[ $name ] = $filter;
			}
		}
		return $resultFilters;
	}

	/**
	 * Replace certain filter name in all configs with different name.
	 * @param array[] &$config Configuration being processed
	 * @param string $oldName
	 * @param string $newName
	 */
	private function replaceFilter( array &$config, $oldName, $newName ) {
		foreach ( $config[ 'analyzer' ] as &$analyzer ) {
			if ( !isset( $analyzer[ 'filter' ] ) ) {
				continue;
			}
			$analyzer[ 'filter' ] = array_map( static function ( $filter ) use ( $oldName, $newName ) {
				if ( $filter === $oldName ) {
					return $newName;
				}
				return $filter;
			}, $analyzer[ 'filter' ] );
		}
	}

	/**
	 * Merge per-language config into the main config.
	 * It will copy specific analyzer and all dependant filters and char_filters.
	 * @param array &$config Main config
	 * @param array $langConfig Per-language config
	 * @param string $name Name for analyzer whose config we're merging
	 * @param string $prefix Prefix for this configuration
	 */
	private function mergeConfig( array &$config, array $langConfig, $name, $prefix ) {
		$analyzer = $langConfig[ 'analyzer' ][ $name ];
		$config[ 'analyzer' ][ $prefix . '_' . $name ] = $analyzer;
		if ( !empty( $analyzer[ 'filter' ] ) ) {
			// Add private filters for this analyzer
			foreach ( $analyzer[ 'filter' ] as $filter ) {
				// Copy filters that are in language config but not in the main config.
				// We would not copy the same filter into the main config since due to
				// the resolution step we know they are the same (otherwise we would have
				// renamed it).
				if ( isset( $langConfig[ 'filter' ][ $filter ] ) &&
					!isset( $config[ 'filter' ][ $filter ] ) ) {
					$config[ 'filter' ][ $filter ] = $langConfig[ 'filter' ][ $filter ];
				}
			}
		}
		if ( !empty( $analyzer[ 'char_filter' ] ) ) {
			// Add private char_filters for this analyzer
			foreach ( $analyzer[ 'char_filter' ] as $filter ) {
				// Copy char_filters that are in lang config but not in the main config.
				// Need to check whether the filter exists in langConfig because some
				// non-configurable filters are defined in plugins and do not have a
				// local definition (e.g., camelCase_splitter)
				if ( isset( $langConfig[ 'char_filter' ][ $filter ] ) &&
					!isset( $config[ 'char_filter' ][ $filter ] ) ) {
					$config[ 'char_filter' ][ $filter ] = $langConfig[ 'char_filter' ][ $filter ];
				}
			}
		}
		if ( !empty( $analyzer[ 'tokenizer' ] ) ) {
			$tokenizer = $analyzer[ 'tokenizer' ];
			if ( isset( $langConfig[ 'tokenizer' ][ $tokenizer ] ) &&
					!isset( $config[ 'tokenizer' ][ $tokenizer ] ) ) {
				$config[ 'tokenizer' ][ $tokenizer ] = $langConfig[ 'tokenizer' ][ $tokenizer ];
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
			// Char filters & Tokenizers are nicely namespaced
			// Filters are NOT - e.g. lowercase & icu_folding filters are different for different
			// languages! So we need to do some disambiguation here.
			$langConfig[ 'filter' ] =
				$this->resolveFilters( $langConfig, $config[ 'filter' ], $defaultFilters, $lang );
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
	 * @return bool true if the textify plugin is available.
	 */
	public function isTextifyAvailable() {
		return $this->textify;
	}

	/**
	 * update languages with global custom filters (e.g., homoglyph & nnbsp filters)
	 *
	 * @param mixed[] $config
	 * @param string $language language to add plugin to
	 * @return mixed[] updated config
	 */
	public function enableGlobalCustomFilters( array $config, string $language ) {
		return GlobalCustomFilter::enableGlobalCustomFilters( $config, $language,
			$this->globalCustomFilters, $this->plugins );
	}

	/**
	 * Languages for which we have a custom analysis chain (Elastic built-in or our
	 * own custom analysis). All other languages default to the default analyzer which
	 * isn't too good. Note that this array is sorted alphabetically by value. The
	 * Elastic list is sourced from
	 * https://www.elastic.co/guide/en/elasticsearch/reference/current/analysis-lang-analyzer.html
	 *
	 * @var string[]
	 */
	private $elasticsearchLanguageAnalyzers = [
		'ar' => 'arabic',
		'ary' => 'arabic-moroccan',
		'arz' => 'arabic-egyptian',
		'hy' => 'armenian',
		'eu' => 'basque',
		'bn' => 'bengali',
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
		'et' => 'estonian',
		'fi' => 'finnish',
		'fr' => 'french',
		'gl' => 'galician',
		'de' => 'german',
		'el' => 'greek',
		'hi' => 'hindi',
		'hu' => 'hungarian',
		'id' => 'indonesian',
		'ga' => 'irish',
		'it' => 'italian',
		'lt' => 'lithuanian',
		'lv' => 'latvian',
		'ms' => 'malay',
		'mwl' => 'mirandese',
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
		'ar' => true,
		'ary' => true,
		'arz' => true,
		'bg' => true,
		'bn' => true,
		'bs' => true,
		'ca' => true,
		'cs' => true,
		'ckb' => true,
		'da' => true,
		'de' => true,
		'el' => true,
		'en' => true,
		'en-ca' => true,
		'en-gb' => true,
		'simple' => true,
		'eo' => true,
		'es' => true,
		'et' => true,
		'eu' => true,
		'fa' => true,
		'fi' => true,
		'fr' => true,
		'ga' => true,
		'gl' => true,
		'he' => true,
		'hi' => true,
		'hr' => true,
		'hu' => true,
		'hy' => true,
		'ja' => true,
		'lt' => true,
		'lv' => true,
		'nb' => true,
		'nl' => true,
		'nn' => true,
		'pt' => true,
		'pt-br' => true,
		'ro' => true,
		'ru' => true,
		'sh' => true,
		'sk' => true,
		'sr' => true,
		'sv' => true,
		'th' => true,
		'tr' => true,
	];

	/**
	 * @var bool[] indexed by language code, indicates whether languages should always
	 * replace the standard tokenizer with the icu_tokenizer by default (true), or should
	 * never use any version of the icu_tokenizer, even when icu_token_repair is
	 * available (false). (Reminder to future readers of this code: languages with
	 * non-standard tokenizers in the text field, like zh/Chinese, still use icu_tokenizer
	 * in the plain fields & suggest fields.)
	 */
	private $languagesWithIcuTokenization = [
		// true => use any version of icu_tokenizer available over the standard tokenizer
		'bo' => true,
		'dz' => true,
		'gan' => true,
		'ja' => true,
		'km' => true,
		'lo' => true,
		'my' => true,
		'th' => true,
		'wuu' => true,
		'zh' => true,
		'lzh' => true, // zh-classical
		'zh-classical' => true, // deprecated code for lzh
		'yue' => true, // zh-yue
		'zh-yue' => true, // deprecated code for yue
		// This list below are languages that may use use mixed scripts
		'bug' => true,
		'cdo' => true,
		'cr' => true,
		'hak' => true,
		'jv' => true,
		'nan' => true, // zh-min-nan
		'zh-min-nan' => true, // deprecated code for nan

		// false => do not use any version of icu_tokenizer (i.e., textify_icu_tokenzier)
		// over the standard tokenizer, even when icu_token_repair is available
		// 'xyz' => false, // <-- example entry for now, since there are no actual instances
	];

	/**
	 * @var array[]
	 */
	private $elasticsearchLanguageAnalyzersFromPlugins = [
		/**
		 * multiple plugin requirement can be comma separated
		 *
		 * Polish: https://www.mediawiki.org/wiki/User:TJones_(WMF)/T154517
		 * Ukrainian: https://www.mediawiki.org/wiki/User:TJones_(WMF)/T160106
		 * Chinese: https://www.mediawiki.org/wiki/User:TJones_(WMF)/T158203
		 * Hebrew: https://www.mediawiki.org/wiki/User:TJones_(WMF)/T162741
		 * Serbian: https://www.mediawiki.org/wiki/User:TJones_(WMF)/T183015
		 * Bosnian, Croatian, and Serbo-Croatian:
		 *    https://www.mediawiki.org/wiki/User:TJones_(WMF)/T192395
		 * Slovak: https://www.mediawiki.org/wiki/User:TJones_(WMF)/T190815
		 * Esperanto: https://www.mediawiki.org/wiki/User:TJones_(WMF)/T202173
		 * Korean: https://www.mediawiki.org/wiki/User:TJones_(WMF)/T206874
		 * Khmer: https://www.mediawiki.org/wiki/User:TJones_(WMF)/T185721
		 *
		 * extra-analysis-ukrainian should follow analysis-ukrainian, so that
		 * ukrainian-unpacked can overwrite value for uk if both are present.
		 */

		'analysis-stempel' => [ 'pl' => 'polish' ],
		'analysis-kuromoji' => [ 'ja' => 'japanese' ],
		'analysis-stconvert,analysis-smartcn' => [ 'zh' => 'chinese' ],
		'analysis-hebrew' => [ 'he' => 'hebrew' ],
		'analysis-ukrainian' => [ 'uk' => 'ukrainian' ],
		'extra-analysis-ukrainian' => [ 'uk' => 'ukrainian-unpacked' ],
		'extra-analysis-esperanto' => [ 'eo' => 'esperanto' ],
		'extra-analysis-serbian' => [ 'bs' => 'bosnian', 'hr' => 'croatian',
			'sh' => 'serbo-croatian', 'sr' => 'serbian' ],
		'extra-analysis-slovak' => [ 'sk' => 'slovak' ],
		'analysis-nori' => [ 'ko' => 'korean' ],
		'extra-analysis-khmer' => [ 'km' => 'khmer' ],
	];

	/**
	 * Set up global custom filters
	 *
	 * @return array
	 */
	private static function buildGlobalCustomFilters(): array {
		$gcf = [
			//////////////////////////
			// char filters
			'globo_norm' => new GlobalCustomFilter( 'char_filter' ),

			'acronym_fixer' => ( new GlobalCustomFilter( 'char_filter' ) )->
				// follow armenian_charfilter, which normalizes another period-like
				// character, if it is being used
				setRequiredPlugins( [ 'extra-analysis-textify' ] )->
				setFallbackFilter( 'regex_acronym_fixer' )->
				setMustFollowFilters( [ 'armenian_charfilter' ] ),

			'camelCase_splitter' => ( new GlobalCustomFilter( 'char_filter' ) )->
				// camelCase should generally follow acronyms so a.c.r.o.C.a.m.e.l.
				// is treated the same as acroCamel (real example: G.m.b.H. vs GmbH)
				setRequiredPlugins( [ 'extra-analysis-textify' ] )->
				setFallbackFilter( 'regex_camelCase' )->
				setMustFollowFilters( [ 'acronym_fixer', 'regex_acronym_fixer' ] ),

			'word_break_helper' => ( new GlobalCustomFilter( 'char_filter' ) )->
				// * acronyms should be fixed before converting period to spaces
				// * follow armenian_charfilter, which normalizes another period-like
				//   character, if it is being used
				setMustFollowFilters( [ 'acronym_fixer', 'regex_acronym_fixer',
					'armenian_charfilter' ] )->
				setLanguageDenyList( [ 'ko', 'zh' ] ),

			//////////////////////////
			// token filters
			'icu_token_repair' => ( new GlobalCustomFilter( 'filter' ) )->
				// apply icu_token_repair to icu_tokenizer-using analyzers
				// (default == text & text_search)
				setRequiredPlugins( [ 'extra-analysis-textify' ] )->
				setRequiredTokenizer( 'textify_icu_tokenizer' ),

			'icutokrep_no_camel_split' => ( new GlobalCustomFilter( 'filter' ) )->
				// apply icu_token_repair variant to non-camelCase-splitting
				// icu_tokenizer-using analyzers when textify_icu_tokenizer is used
				setRequiredPlugins( [ 'extra-analysis-textify' ] )->
				setApplyToAnalyzers( [ 'plain', 'plain_search', 'suggest', 'suggest_reverse',
					'source_text_plain', 'source_text_plain_search', 'word_prefix' ] )->
				setRequiredTokenizer( 'textify_icu_tokenizer' ),

			'homoglyph_norm' => ( new GlobalCustomFilter( 'filter' ) )->
				// aggressive_splitting has weird graph problems and creating
				// multiple tokens makes it blow up
				setRequiredPlugins( [ 'extra-analysis-homoglyph' ] )->
				setMustFollowFilters( [ 'aggressive_splitting' ] ),
		];
		// reverse the array so that items are ordered (approximately, modulo incompatible
		// filters) in the order specified here
		return array_reverse( $gcf );
	}

}
