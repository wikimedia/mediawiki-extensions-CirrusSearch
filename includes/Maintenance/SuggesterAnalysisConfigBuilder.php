<?php

namespace CirrusSearch\Maintenance;

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
	const VERSION = "1.4";

	/**
	 * Build an analysis config with sane defaults
	 *
	 * @param string $language Config language
	 * @return array
	 */
	protected function defaults( $language ) {
		// Use default lowercase filter
		$lowercase_type = [ 'type' => 'lowercase' ];
		if ( $this->isIcuAvailable() ) {
			$lowercase_type = [
				"type" => "icu_normalizer",
				"name" => "nfkc_cf",
			];
		}
		// Use the default Lucene ASCII filter
		$folding_type = [ 'type' => 'asciifolding' ];
		if ( $this->shouldActivateIcuFolding( $language ) ) {
			// Use ICU Folding if the plugin is available and activated in the config
			$folding_type = [ 'type' => 'icu_folding' ];
			$unicodeSetFilter = $this->getICUSetFilter( $language );
			if ( !empty( $unicodeSetFilter ) ) {
				$folding_type['unicodeSetFilter'] = $unicodeSetFilter;
			}
		}
		$textTokenizer = 'standard';
		$plainTokenizer = 'whitespace';
		if ( $this->shouldActivateIcuTokenization( $language ) ) {
			$textTokenizer = 'icu_tokenizer';
			// We cannot use the icu_tokenizer for plain here
			// even if icu tokenization is mostly needed for languages
			// where space is not used to break words. We don't want
			// to break some punctuation chars like ':'
		}
		$defaults = [
			'char_filter' => [
				'word_break_helper' => [
					'type' => 'mapping',
					'mappings' => [
						'_=>\u0020', // a space for mw
						',=>\u0020', // useful for "Lastname, Firstname"
						'"=>\u0020', // " certainly phrase search?
						'-=>\u0020', // useful for hyphenated names
						"'=>\u0020",       // Useful for finding names
						'\u2019=>\u0020',  // Unicode right single quote
						'\u02BC=>\u0020',  // Unicode modifier letter apostrophe
						// Not sure about ( and )...
						// very useful to search for :
						// "john smith explo" instead of "john smith (expl"
						// but annoying to search for "(C)"
						// ')=>\u0020',
						// '(=>\u0020',
						// Ignoring : can be misleading for expert users
						// Because we will return unrelated pages when the user
						// search for "magic keywords" like WP:WP which are sometimes
						// pages in the main namespace that redirect to other namespace
						// ':=>\u0020',
						// Others are the ones ignored by common search engines
						';=>\u0020',
						'\\[=>\u0020',
						'\\]=>\u0020',
						'{=>\u0020',
						'}=>\u0020',
						'\\\\=>\u0020',
						// Unicode white spaces
						// cause issues with completion
						// only few of them where actually
						// identified as problematic but
						// more are added for extra safety
						// see: T156234
						// TODO: reevaluate with es5
						'\u00a0=>\u0020',
						'\u1680=>\u0020',
						'\u180e=>\u0020',
						'\u2000=>\u0020',
						'\u2001=>\u0020',
						'\u2002=>\u0020',
						'\u2003=>\u0020',
						'\u2004=>\u0020',
						'\u2005=>\u0020',
						'\u2006=>\u0020',
						'\u2007=>\u0020',
						'\u2008=>\u0020',
						'\u2009=>\u0020',
						'\u200a=>\u0020',
						'\u200b=>\u0020', // causes issue
						'\u200c=>\u0020', // causes issue
						'\u200d=>\u0020', // causes issue
						'\u202f=>\u0020',
						'\u205f=>\u0020',
						'\u3000=>\u0020',
						'\ufeff=>\u0020', // causes issue
					],
				],
			],
			'filter' => [
				"stop_filter" => [
					"type" => "stop",
					"stopwords" => "_none_",
					"remove_trailing" => "true"
				],
				"lowercase" => $lowercase_type,
				"accentfolding" => $folding_type,
				"token_limit" => [
					"type" => "limit",
					"max_token_count" => "20"
				],
				// Workaround what seems to be a bug in the
				// completion suggester, empty tokens cause an
				// issue similar to
				// https://github.com/elastic/elasticsearch/pull/11158
				// can be removed with es5 if we want
				"remove_empty" => [
					"type" => "length",
					"min" => 1,
				],
			],
			'analyzer' => [
				"stop_analyzer" => [
					"type" => "custom",
					"filter" => [
						"lowercase",
						"stop_filter",
						"accentfolding",
						"remove_empty",
						"token_limit"
					],
					"tokenizer" => $textTokenizer,
				],
				// We do not remove stop words when searching,
				// this leads to extremely weird behaviors while
				// writing "to be or no to be"
				"stop_analyzer_search" => [
					"type" => "custom",
					"filter" => [
						"lowercase",
						"accentfolding",
						"remove_empty",
						"token_limit"
					],
					"tokenizer" => $textTokenizer,
				],
				"plain" => [
					"type" => "custom",
					"char_filter" => [ 'word_break_helper' ],
					"filter" => [
						"remove_empty",
						"token_limit",
						"lowercase"
					],
					"tokenizer" => $plainTokenizer,
				],
				"plain_search" => [
					"type" => "custom",
					"char_filter" => [ 'word_break_helper' ],
					"filter" => [
						"remove_empty",
						"token_limit",
						"lowercase"
					],
					"tokenizer" => $plainTokenizer,
				],
			],
		];
		if ( $this->config->getElement( 'CirrusSearchCompletionSuggesterSubphrases', 'build' ) ) {
			$defaults['analyzer']['subphrases'] = [
				"type" => "custom",
				"filter" => [
					"lowercase",
					"accentfolding",
					"remove_empty",
					"token_limit"
				],
				"tokenizer" => $textTokenizer,
			];
			$defaults['analyzer']['subphrases_search'] = [
				"type" => "custom",
				"filter" => [
					"lowercase",
					"accentfolding",
					"remove_empty",
					"token_limit"
				],
				"tokenizer" => $textTokenizer,
			];
		}
		return $defaults;
	}

	/**
	 * @param array $config
	 * @param string $language
	 * @return array
	 */
	private function customize( array $config, $language ) {
		$defaultStopSet = $this->getDefaultStopSet( $language );
		$config['filter']['stop_filter']['stopwords'] = $defaultStopSet;

		switch ( $this->getDefaultTextAnalyzerType( $language ) ) {
		// Please add languages in alphabetical order.
		case 'russian':
			// T102298 ignore combining acute / stress accents
			$config[ 'char_filter' ][ 'word_break_helper' ][ 'mappings' ][] = '\u0301=>';

			// The Russian analyzer is also used for Ukrainian and Rusyn for now, so processing that's
			// very specific to Russian should be separated out
			if ( $language === 'ru' ) {
				// T124592 fold ё=>е and Ё=>Е, precomposed or with combining diacritic
				$config[ 'char_filter' ][ 'word_break_helper' ][ 'mappings' ][] = '\u0451=>\u0435';
				$config[ 'char_filter' ][ 'word_break_helper' ][ 'mappings' ][] = '\u0401=>\u0415';
				$config[ 'char_filter' ][ 'word_break_helper' ][ 'mappings' ][] = '\u0435\u0308=>\u0435';
				$config[ 'char_filter' ][ 'word_break_helper' ][ 'mappings' ][] = '\u0415\u0308=>\u0415';
			}
			break;
		}

		if ( $this->isIcuAvailable() ) {
			foreach ( $config[ 'analyzer' ] as $k => &$analyzer ) {
				if ( $k != "stop_analyzer" && $k != "stop_analyzer_search" ) {
					continue;
				}
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
	 * Build the analysis config.
	 *
	 * @param string|null $language Config language
	 * @return array the analysis config
	 */
	public function buildConfig( $language = null ) {
		if ( $language === null ) {
			$language = $this->defaultLanguage;
		}
		return $this->customize( $this->defaults( $language ), $language );
	}

	/** @var string[] */
	private static $stopwords = [
		'ar' => '_arabic_',
		'hy' => '_armenian_',
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
		'lt' => '_lithuanian_',
		'lv' => '_latvian_',
		'ga' => '_irish_',
		'it' => '_italian_',
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
	];

	/**
	 * @param string $lang
	 * @return string
	 */
	private function getDefaultStopSet( $lang ) {
		return isset( self::$stopwords[$lang] ) ? self::$stopwords[$lang] : '_none_';
	}

	/**
	 * @param string $lang
	 * @return bool
	 */
	public static function hasStopWords( $lang ) {
		return isset( self::$stopwords[$lang] );
	}
}
