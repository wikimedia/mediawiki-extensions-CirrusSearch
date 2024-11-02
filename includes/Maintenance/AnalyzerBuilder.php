<?php

namespace CirrusSearch\Maintenance;

use MediaWiki\Config\ConfigException;

/**
 * Builds one elasticsearch analyzer to add to an analysis config array.
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
class AnalyzerBuilder {
	/**
	 * Indicate that filters should be automatically appended or prepended, rather
	 * than inserted before a given filter.
	 */
	public const APPEND = 1;
	public const PREPEND = 2;

	/** @var string */
	private $langName;

	/** @var string */
	private $analyzerName = 'text';

	/** @var bool */
	private $icuEnabled;

	/** @var string[]|null list of char_filters */
	private $charFilters;

	/** @var string|null name of tokenizer */
	private $tokenizer = 'standard';

	/** @var string[]|null list of filters */
	private $filters;

	/** @var string[]|null list of lang-specific character filter mappings */
	private $charMap;

	/** @var bool */
	private $charMapLimited = false;

	/** @var string|null */
	private $charMapName;

	/** @var int|null Unicode value for script-specific zero */
	private $langZero;

	/** @var bool should langZero's map be reversed (Arabic to non-Arabic)? */
	private $numCharMapReversed = false;

	/** @var string|null name of char filter mapping digits (using $langZero) */
	private $numCharMapName;

	/** @var bool is elision processing case INsensitive? */
	private $elisionArticleCase = true;

	/** @var string[]|null list of articles to elide */
	private $elisionArticles;

	/** @var string|null */
	private $elisionName;

	/** @var string|null */
	private $langLowercase;

	/** @var mixed|null stopword _list_ or array of stopwords */
	private $customStopList;

	/** @var string|null */
	private $stopName;

	/** @var mixed|null stopword _list_ or array of stopwords */
	private $extraStopList;

	/** @var string|null */
	private $extraStopName;

	/** @var bool|null */
	private $extraStopIgnoreCase;

	/** @var string|null */
	private $extraStemmerLang;

	/** @var string|null */
	private $extraStemmerName;

	/** @var string[]|null list of stemmer override rules */
	private $overrideRules;

	/** @var string|null */
	private $overrideName;

	/**********
	 * The properties below are only used by unpacked analyzers
	 */

	/** @var bool */
	private $unpacked = false;

	/** @var array<int, array<string, string[]>> */
	private $insertFilterList = [];

	/** @var bool */
	private $useStemmer = true;

	/** @var string|null */
	private $stemmerLang;

	/** @var string|null folding flavor to use (null for none) */
	private $folding = 'icu_folding';

	/** @var string|null */
	private $removeEmpty;

	/** @var string|null */
	private $decimalDigit;

	/**
	 * @param string $langName
	 * @param bool $icuEnabled
	 */
	public function __construct( string $langName, bool $icuEnabled = false ) {
		$this->langName = $langName;
		$this->icuEnabled = $icuEnabled;
	}

	/**
	 * @param string $langName
	 * @return self
	 */
	public function withLangName( string $langName ): self {
		$this->langName = $langName;
		return $this;
	}

	/**
	 * @param string[] $charFilters
	 * @return self
	 */
	public function withCharFilters( array $charFilters ): self {
		$this->charFilters = $charFilters;
		return $this;
	}

	/**
	 * @param string $tokenizer
	 * @return self
	 */
	public function withTokenizer( string $tokenizer ): self {
		$this->tokenizer = $tokenizer;
		return $this;
	}

	/**
	 * @param string[] $filters
	 * @return self
	 */
	public function withFilters( array $filters ): self {
		$this->filters = $filters;
		return $this;
	}

	/**
	 * @param string[] $mappings
	 * @param string|null $name
	 * @param bool $limited
	 * @return self
	 */
	public function withCharMap( array $mappings, ?string $name = null, bool $limited = false ): self {
		$this->charMap = $mappings;
		$this->charMapName = $name ?? "{$this->langName}_charfilter";
		$this->charMapLimited = false;
		return $this;
	}

	/**
	 * @param string[] $mappings
	 * @param string|null $name
	 * @return self
	 */
	public function withLimitedCharMap( array $mappings, ?string $name = null ): self {
		return $this->withCharMap( $mappings, $name, true );
	}

	/**
	 * @param int $langZero
	 * @param string|null $name
	 * @return self
	 */
	public function withReversedNumberCharFilter( int $langZero, ?string $name = null ): self {
		$this->withNumberCharFilter( $langZero, $name, true );
		return $this;
	}

	/**
	 * @param int $langZero
	 * @param string|null $name
	 * @param bool $reversed reverse the mapping from Arabic to non-Arabic
	 * @return self
	 */
	public function withNumberCharFilter( int $langZero, ?string $name = null, bool $reversed = false ): self {
		$defName = $reversed ? "{$this->langName}_reversed_numbers" : "{$this->langName}_numbers";
		$this->langZero = $langZero;
		$this->numCharMapName = $name ?? $defName;
		$this->numCharMapReversed = $reversed;
		return $this;
	}

	/**
	 * @param string[] $articles "articles" to be elided
	 * @param bool $articleCase whether elision is case insensitive
	 * @return self
	 */
	public function withElision( array $articles, bool $articleCase = true ): self {
		$this->elisionArticleCase = $articleCase;
		$this->elisionArticles = $articles;
		$this->elisionName = "{$this->langName}_elision";
		return $this;
	}

	/**
	 * @param string|null $name
	 * @return self
	 */
	public function withLangLowercase( ?string $name = null ): self {
		$this->langLowercase = $name ?: $this->langName;
		return $this;
	}

	/**
	 * @param mixed $stop pre-defined list like _french_ or an array of stopwords
	 * @param string|null $name
	 * @return self
	 */
	public function withStop( $stop, ?string $name = null ): self {
		$this->customStopList = $stop;
		$this->stopName = $name ?? "{$this->langName}_stop";
		return $this;
	}

	/**
	 * @param mixed $stop pre-defined list like _french_ or an array of stopwords
	 * @param string $name
	 * @param mixed $beforeFilter filter to insert extra stop before
	 * @param bool|null $ignoreCase
	 * @return self
	 */
	public function withExtraStop( $stop, string $name, $beforeFilter = self::APPEND,
			?bool $ignoreCase = null ): self {
		$this->extraStopList = $stop;
		$this->extraStopName = $name;
		$this->extraStopIgnoreCase = $ignoreCase;
		$this->insertFiltersBefore( $beforeFilter, [ $name ] );
		return $this;
	}

	/**
	 * @param string $lang
	 * @param string|null $name
	 * @return self
	 */
	public function withExtraStemmer( string $lang, ?string $name = null ): self {
		$this->extraStemmerLang = $lang;
		$this->extraStemmerName = $name ?? $lang;
		return $this;
	}

	/**
	 * Rules can be a single rule string, or an array of rules
	 *
	 * @param mixed $rules stemmer override rules
	 * @param string|null $name
	 * @return self
	 */
	public function withStemmerOverride( $rules, ?string $name = null ): self {
		$this->overrideRules = $rules;
		$this->overrideName = $name ?? "{$this->langName}_override";
		return $this;
	}

	/**********
	 * The with.., omit.., and insert.. methods below are only used by unpacked analyzers
	 */

	/** @return self */
	public function withUnpackedAnalyzer(): self {
		$this->unpacked = true;
		return $this;
	}

	private function unpackedCheck(): void {
		if ( !$this->unpacked ) {
			$caller = debug_backtrace()[1]['function'];
			throw new ConfigException( "$caller() is only compatible with unpacked analyzers;" .
				"call withUnpackedAnalyzer() before calling $caller()." );
		}
	}

	/**
	 * @param mixed $beforeFilter specific filter to insert $filters before; use APPEND
	 *                            or PREPEND to always add to beginning or end of the list
	 * @param string[] $filterList list of additional filters to insert
	 * @return self
	 */
	public function insertFiltersBefore( $beforeFilter, array $filterList ): self {
		$this->unpackedCheck();
		$this->insertFilterList[] = [ $beforeFilter => $filterList ];
		return $this;
	}

	/**
	 * @param string[] $filterList list of additional filters to append
	 * @return self
	 */
	public function appendFilters( array $filterList ): self {
		$this->unpackedCheck();
		$this->insertFiltersBefore( self::APPEND, $filterList );
		return $this;
	}

	/**
	 * @param string[] $filterList list of additional filters to prepend
	 * @return self
	 */
	public function prependFilters( array $filterList ): self {
		$this->unpackedCheck();
		$this->insertFiltersBefore( self::PREPEND, $filterList );
		return $this;
	}

	/** @return self */
	public function withLightStemmer(): self {
		$this->unpackedCheck();
		$this->stemmerLang = "light_{$this->langName}";
		return $this;
	}

	/** @return self */
	public function omitStemmer(): self {
		$this->unpackedCheck();
		$this->useStemmer = false;
		return $this;
	}

	/** @return self */
	public function withAsciifolding(): self {
		$this->unpackedCheck();
		$this->folding = 'asciifolding';
		return $this;
	}

	/** @return self */
	public function omitFolding(): self {
		$this->unpackedCheck();
		$this->folding = '';
		return $this;
	}

	/** @return self */
	public function withRemoveEmpty(): self {
		$this->unpackedCheck();
		$this->removeEmpty = 'remove_empty';
		return $this;
	}

	/** @return self */
	public function withDecimalDigit(): self {
		$this->unpackedCheck();
		$this->decimalDigit = 'decimal_digit';
		return $this;
	}

	/**
	 * Create a basic analyzer with support for various common options
	 *
	 * Can create various filters and character filters as specified.
	 * None are automatically added to the char_filter or filter list
	 * because the best order for these basic analyzers depends on the
	 * details of various third-party plugins.
	 *
	 * type: custom
	 * tokenizer: standard
	 * char_filter: as per $this->charFilters
	 * filter: as per $this->filters
	 *
	 * @param mixed[] $config to be updated
	 * @return mixed[] updated config
	 */
	public function build( array $config ): array {
		$langStem = "{$this->langName}_stemmer";

		if ( $this->unpacked ) {
			// Analyzer config for char_filter and filter will be in the order below,
			// if the relevant filters are enabled/configured.
			//
			// type: custom
			// tokenizer: standard
			// char_filter: lang_charfilter, lang_numbers
			// filter: elision, aggressive_splitting, lowercase, stopwords, lang_norm,
			//         stemmer_override, stemmer, folding, remove_empty
			if ( $this->useStemmer ) {
				$this->stemmerLang ??= $this->langName;
			} else {
				$langStem = '';
			}
			$this->withStop( $this->customStopList ?? "_{$this->langName}_" );

			// remove icu_folding if icu plugin unavailable or unwanted
			if ( $this->folding == 'icu_folding' ) {
				if ( !$this->icuEnabled ) {
					$this->folding = '';
				}
			}

			// build up the char_filter list--everything is optional
			$this->charFilters[] = $this->charMapName;
			$this->charFilters[] = $this->numCharMapName;

			// remove 'falsey' (== not configured) values from the list
			$this->charFilters = array_values( array_filter( $this->charFilters ) );

			// build up the filter list--lowercase, stop, and stem are required
			$this->filters[] = $this->elisionName;
			$this->filters[] = 'lowercase';
			$this->filters[] = $this->decimalDigit;
			$this->filters[] = $this->stopName;
			$this->filters[] = $this->overrideName;
			$this->filters[] = $langStem;
			$this->filters[] = $this->folding;
			$this->filters[] = $this->removeEmpty;

			// remove 'falsey' (== not configured) values from the list
			$this->filters = array_values( array_filter( $this->filters ) );

			// iterate over all lists of sets of filters to insert, in order, and insert
			// them before the specified filter. If no such filter exists, $idx == -1 and
			// the filters will be prepended, but you shouldn't count on that. APPEND and
			// PREPEND constants can be used to add to beginning or end, regardless of
			// other filters
			foreach ( $this->insertFilterList as $filterPatch ) {
				foreach ( $filterPatch as $beforeFilter => $filterList ) {
					switch ( $beforeFilter ) {
						case self::APPEND:
							$this->filters = array_merge( $this->filters, $filterList );
							break;
						case self::PREPEND:
							$this->filters = array_merge( $filterList, $this->filters );
							break;
						default:
							$idx = array_search( $beforeFilter, $this->filters );
							array_splice( $this->filters, $idx, 0, $filterList );
							break;
					}
				}
			}

		} else {
			// for simple filter lists, remove icu_folding if ICU not enabled
			if ( !$this->icuEnabled ) {
				$if_idx = array_search( 'icu_folding', $this->filters );
				if ( $if_idx !== false ) {
					array_splice( $this->filters, $if_idx, 1 );
				}
			}
		}

		$config[ 'analyzer' ][ $this->analyzerName ] = [
			'type' => 'custom',
			'tokenizer' => $this->tokenizer,
		];

		if ( $this->charMapName ) {
			$config[ 'char_filter' ][ $this->charMapName ] =
				$this->mappingCharFilter( $this->charMap, $this->charMapLimited );
		}

		if ( $this->numCharMapName ) {
			$config[ 'char_filter' ][ $this->numCharMapName ] =
				$this->numberCharFilter( $this->langZero, $this->numCharMapReversed );
		}

		if ( $this->elisionName ) {
			$config[ 'filter' ][ $this->elisionName ] =
				$this->elisionFilter( $this->elisionArticles, $this->elisionArticleCase );
		}

		if ( $this->langLowercase ) {
			$config[ 'filter' ][ 'lowercase' ][ 'language' ] = $this->langLowercase;
		}

		if ( $this->overrideName ) {
			$config[ 'filter' ][ $this->overrideName ] =
				$this->overrideFilter( $this->overrideRules );
		}

		if ( $this->stopName ) {
			$config[ 'filter' ][ $this->stopName ] =
				$this->stopFilterFromList( $this->customStopList );
		}

		if ( $this->extraStopName ) {
			$config[ 'filter' ][ $this->extraStopName ] =
				$this->stopFilterFromList( $this->extraStopList, $this->extraStopIgnoreCase );
		}

		if ( $this->charFilters ) {
			$config[ 'analyzer' ][ $this->analyzerName ][ 'char_filter' ] = $this->charFilters;
		}

		if ( $this->filters ) {
			$config[ 'analyzer' ][ $this->analyzerName ][ 'filter' ] = $this->filters;
		}

		if ( $this->stemmerLang && $this->useStemmer ) {
			$config[ 'filter' ][ $langStem ] =
				$this->stemmerFilter( $this->stemmerLang );
		}

		if ( $this->extraStemmerName ) {
			$config[ 'filter' ][ $this->extraStemmerName ] =
				$this->stemmerFilter( $this->extraStemmerLang );
		}

		return $config;
	}

	/**
	 * Create a pattern_replace filter/char_filter with the mappings provided.
	 *
	 * @param string $pat
	 * @param string $repl
	 * @return mixed[] filter
	 */
	public static function patternFilter( string $pat, string $repl = '' ): array {
		return [ 'type' => 'pattern_replace', 'pattern' => $pat, 'replacement' => $repl ];
	}

	/**
	 * Create a mapping or limited_mapping character filter with the mappings provided.
	 *
	 * @param string[] $mappings
	 * @param bool $limited
	 * @return mixed[] character filter
	 */
	public static function mappingCharFilter( array $mappings, bool $limited ): array {
		$type = $limited ? 'limited_mapping' : 'mapping';
		return [ 'type' => $type, 'mappings' => $mappings ];
	}

	/**
	 * Create a character filter that maps non-Arabic digits (e.g., ០-៩ or ０-９) to
	 * Arabic digits (0-9). Since they are usually all in a row, we just need the
	 * starting digit (equal to 0).
	 *
	 * Optionally reverse the mapping from Arabic to non-Arabic. For example, the ICU
	 * tokenizer works better on tokenizing Thai digits in Thai text than it does on
	 * Arabic digits.
	 *
	 * @param int $langZero
	 * @param bool $reversed reverse the mapping from Arabic to non-Arabic
	 * @return mixed[] character filter
	 */
	public static function numberCharFilter( int $langZero, bool $reversed = false ): array {
		$numMap = [];
		for ( $i = 0; $i <= 9; $i++ ) {
			if ( $reversed ) {
				$numMap[] = sprintf( '%d=>\\u%04x', $i, $langZero + $i );
			} else {
				$numMap[] = sprintf( '\\u%04x=>%d', $langZero + $i, $i );
			}
		}
		return self::mappingCharFilter( $numMap, true );
	}

	/**
	 * Create an elision filter with the "articles" provided; $case determines whether
	 * stripping is case sensitive or not
	 *
	 * @param string[] $articles
	 * @param bool $case
	 * @return mixed[] token filter
	 */
	public static function elisionFilter( array $articles, bool $case = true ): array {
		return [ 'type' => 'elision', 'articles_case' => $case, 'articles' => $articles ];
	}

	/**
	 * Create a stop word filter with the provided config. The config can be an array
	 * of stop words, or a string like _french_ that refers to a pre-defined list.
	 *
	 * @param mixed $stopwords
	 * @param bool|null $ignoreCase
	 * @return mixed[] token filter
	 */
	public static function stopFilterFromList( $stopwords, ?bool $ignoreCase = null ): array {
		$retArray = [ 'type' => 'stop', 'stopwords' => $stopwords ];
		if ( isset( $ignoreCase ) ) {
			$retArray['ignore_case'] = $ignoreCase;
		}
		return $retArray;
	}

	/**
	 * Create an stemming override filter with the rules provided, which can be a string
	 * with one rule or an array of such rules
	 *
	 * @param mixed $rules
	 * @return mixed[] token filter
	 */
	private function overrideFilter( $rules ): array {
		return [ 'type' => 'stemmer_override', 'rules' => $rules ];
	}

	/**
	 * Create a stemmer filter with the provided config.
	 *
	 * @param string $stemmer
	 * @return mixed[] token filter
	 */
	public static function stemmerFilter( string $stemmer ): array {
		return [ 'type' => 'stemmer', 'language' => $stemmer ];
	}

}
