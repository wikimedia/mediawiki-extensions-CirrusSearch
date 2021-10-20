<?php

namespace CirrusSearch\Maintenance;

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
	private $analyzerName;

	/** @var string[]|null list of char_filters */
	private $charFilters;

	/** @var string[]|null list of filters */
	private $filters;

	/** @var string[]|null list of lang-specific character filter mappings */
	private $charMap;

	/** @var string|null */
	private $charMapName;

	/** @var int|null Unicode value for script-specific zero */
	private $langZero;

	/** @var string|null name of char filter mapping digits (using $langZero) */
	private $numCharMapName;

	/** @var bool is elision processing case INsensitive? */
	private $elisionArticleCase = true;

	/** @var string[]|null list of articles to elide */
	private $elisionArticles;

	/** @var string|null */
	private $elisionName;

	/** @var bool use language-specific lowercasing? */
	private $langLowercase = false;

	/** @var mixed|null stopword _list_ or array of stopwords */
	private $customStop;

	/** @var string|null */
	private $stopName;

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

	/** @var string */
	private $dottedIFix = 'dotted_I_fix';

	/** @var string|null */
	private $wordBreakHelper;

	/** @var string|null */
	private $aggressiveSplitting;

	/** @var string|null */
	private $stemmerName;

	/** @var string|null asciifolding flavor to use (null for none) */
	private $asciifolding = 'asciifolding';

	/** @var string|null */
	private $removeEmpty;

	/**
	 * @param string $langName
	 * @param string $analyzerName (default to 'text')
	 */
	public function __construct( string $langName, string $analyzerName = 'text' ) {
		$this->langName = $langName;
		$this->analyzerName = $analyzerName;
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
	 * @param string[] $filters
	 * @return self
	 */
	public function withFilters( array $filters ): self {
		$this->filters = $filters;
		return $this;
	}

	/**
	 * @param string[] $mappings
	 * @return self
	 */
	public function withCharMap( array $mappings ): self {
		$this->charMap = $mappings;
		$this->charMapName = "{$this->langName}_charfilter";
		return $this;
	}

	/**
	 * @param int $langZero
	 * @return self
	 */
	public function withNumberCharFilter( int $langZero ): self {
		$this->langZero = $langZero;
		$this->numCharMapName = "{$this->langName}_numbers";
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
		$this->elisionName = "{$this->langName}_elision"; // $this->langName . '_elision';
		return $this;
	}

	/** @return self */
	public function withLangLowercase(): self {
		$this->langLowercase = true;
		return $this;
	}

	/**
	 * @param mixed $stop pre-defined list like _french_ or an array of stopwords
	 * @return self
	 */
	public function withStop( $stop ): self {
		$this->customStop = $stop;
		$this->stopName = "{$this->langName}_stop";
		return $this;
	}

	/**
	 * @param string[] $rules stemmer override rules
	 * @return self
	 */
	public function withStemmerOverride( array $rules ): self {
		$this->overrideRules = $rules;
		$this->overrideName = "{$this->langName}_override";
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

	private function unpackedCheck() {
		if ( !$this->unpacked ) {
			$caller = debug_backtrace()[1]['function'];
			throw new \ConfigException( "$caller() is only compatible with unpacked analyzers;" .
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

	/** @return self */
	public function omitDottedI(): self {
		$this->unpackedCheck();
		$this->dottedIFix = '';
		return $this;
	}

	/** @return self */
	public function withWordBreakHelper(): self {
		$this->unpackedCheck();
		$this->wordBreakHelper = 'word_break_helper';
		return $this;
	}

	/** @return self */
	public function withAggressiveSplitting(): self {
		$this->unpackedCheck();
		$this->aggressiveSplitting = 'aggressive_splitting';
		return $this;
	}

	/** @return self */
	public function withLightStemmer(): self {
		$this->unpackedCheck();
		$this->stemmerName = "light_{$this->langName}";
		return $this;
	}

	/** @return self */
	public function withAsciifoldingPreserve(): self {
		$this->unpackedCheck();
		$this->asciifolding = 'asciifolding_preserve';
		return $this;
	}

	/** @return self */
	public function omitAsciifolding(): self {
		$this->unpackedCheck();
		$this->asciifolding = '';
		return $this;
	}

	/** @return self */
	public function withRemoveEmpty(): self {
		$this->unpackedCheck();
		$this->removeEmpty = 'remove_empty';
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
			// char_filter: dotted_I_fix, lang_charfilter, lang_numbers, word_break_helper
			// filter: elision, aggressive_splitting, lowercase, stopwords, lang_norm,
			//         stemmer_override, stemmer, asciifolding, remove_empty
			$this->stemmerName = $this->stemmerName ?? $this->langName;
			$this->withStop( $this->customStop ?? "_{$this->langName}_" );

			// build up the char_filter list--everything is optional
			$this->charFilters[] = $this->dottedIFix;
			$this->charFilters[] = $this->charMapName;
			$this->charFilters[] = $this->numCharMapName;
			$this->charFilters[] = $this->wordBreakHelper;

			// remove 'falsey' (== not configured) values from the list
			$this->charFilters = array_values( array_filter( $this->charFilters ) );

			// build up the filter list--lowercase, stop, and stem are required
			$this->filters[] = $this->elisionName;
			$this->filters[] = $this->aggressiveSplitting;
			$this->filters[] = 'lowercase';
			$this->filters[] = $this->stopName;
			$this->filters[] = $this->overrideName;
			$this->filters[] = $langStem;
			$this->filters[] = $this->asciifolding;
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

		}

		$config[ 'analyzer' ][ $this->analyzerName ] = [
			'type' => 'custom',
			'tokenizer' => 'standard',
		];

		if ( $this->charMapName ) {
			$config[ 'char_filter' ][ $this->charMapName ] =
				$this->mappingCharFilter( $this->charMap );
		}

		if ( $this->numCharMapName ) {
			$config[ 'char_filter' ][ $this->numCharMapName ] =
				$this->numberCharFilter( $this->langZero );
		}

		if ( $this->elisionName ) {
			$config[ 'filter' ][ $this->elisionName ] =
				$this->elisionFilter( $this->elisionArticles, $this->elisionArticleCase );
		}

		if ( $this->langLowercase ) {
			$config[ 'filter' ][ 'lowercase' ][ 'language' ] = $this->langName;
		}

		if ( $this->overrideName ) {
			$config[ 'filter' ][ $this->overrideName ] =
				$this->overrideFilter( $this->overrideRules );
		}

		if ( $this->stopName ) {
			$config[ 'filter' ][ $this->stopName ] =
				$this->stopFilter( $this->customStop );
		}

		if ( $this->charFilters ) {
			$config[ 'analyzer' ][ $this->analyzerName ][ 'char_filter' ] = $this->charFilters;
		}

		if ( $this->filters ) {
			$config[ 'analyzer' ][ $this->analyzerName ][ 'filter' ] = $this->filters;
		}

		if ( $this->stemmerName ) {
			$config[ 'filter' ][ $langStem ] =
				$this->stemmerFilter( $this->stemmerName );
		}

		return $config;
	}

	/**
	 * Create a mapping character filter with the mappings provided.
	 *
	 * @param string[] $mappings
	 * @return mixed[] character filter
	 */
	public static function mappingCharFilter( array $mappings ): array {
		return [ 'type' => 'mapping', 'mappings' => $mappings ];
	}

	/**
	 * Create a character filter that maps non-Arabic digits (e.g., ០-៩ or ０-９) to
	 * Arabic digits (0-9). Since they are usually all in a row, we just need the
	 * starting digit (equal to 0)
	 *
	 * @param int $langZero
	 * @return mixed[] character filter
	 */
	public static function numberCharFilter( int $langZero ): array {
		$numMap = [];
		for ( $i = 0; $i <= 9; $i++ ) {
		  $numMap[] = sprintf( '\\u%04x=>%d', $langZero + $i, $i );
		}
		return self::mappingCharFilter( $numMap );
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
	public static function stopFilter( $stopwords, bool $ignoreCase = null ): array {
		$retArray = [ 'type' => 'stop', 'stopwords' => $stopwords ];
		if ( isset( $ignoreCase ) ) {
			$retArray['ignore_case'] = $ignoreCase;
		}
		return $retArray;
	}

	/**
	 * Create an stemming override filter with the rules provided
	 *
	 * @param string[] $rules
	 * @return mixed[] token filter
	 */
	private function overrideFilter( array $rules ): array {
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
