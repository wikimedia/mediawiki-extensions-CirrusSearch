<?php
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
class CirrusSearchAnalysisConfigBuilder {
	private $language;

	/**
	 * @return array
	 */
	public static function build() {
		$builder = new CirrusSearchAnalysisConfigBuilder();
		return $builder->buildConfig();
	}

	public function __construct() {
		global $wgLanguageCode;
		$this->language = $wgLanguageCode;
	}

	/**
	 * Build the analysis config.
	 * @return array the analysis config
	 */
	public function buildConfig() {
		return $this->customize( $this->defaults() );
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
				'plain' => array(
					// Surprisingly, the Lucene docs claim this works for
					// Chinese, Japanese, and Thai as well.
					// The difference between this and the 'standard'
					// analzyer is the lack of english stop words.
					'type' => 'custom',
					'tokenizer' => 'standard',
					'filter' => array( 'standard', 'lowercase' )
				),
				'suggest' => array(
					'type' => 'custom',
					'tokenizer' => 'standard',
					'filter' => array( 'standard', 'lowercase', 'suggest_shingle' ),
				),
				'prefix' => array(
					'type' => 'custom',
					'tokenizer' => 'prefix',
					'filter' => array( 'lowercase' )
				),
				'lowercase_keyword' => array(
					'type' => 'custom',
					'tokenizer' => 'no_splitting',
					'filter' => array( 'lowercase' )
				),
			),
			'filter' => array(
				'suggest_shingle' => array(
					'type' => 'shingle',
					'min_shingle_size' => 2,
					'max_shingle_size' => 5,
					'output_unigrams' => true,
				),
				'lowercase' => array(
					'type' => 'lowercase',
				),

				'aggressive_splitting' => array(
					'type' => 'word_delimiter',
					'stem_english_possessive' => 'false', // No need
				)
			),
			'tokenizer' => array(
				'prefix' => array(
					'type' => 'edgeNGram',
					'max_gram' => CirrusSearchSearcher::MAX_PREFIX_SEARCH,
				),
				'no_splitting' => array( // Just grab the whole term.
					'type' => 'keyword',
				)
			)
		);
	}

	/**
	 * Customize the default config for the language.
	 */
	private function customize( $config ) {
		global $wgCirrusSearchUseAggressiveSplitting;
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
			if ( $wgCirrusSearchUseAggressiveSplitting ) {
				$filters[] = 'aggressive_splitting';
			}
			$filters[] = 'possessive_english';
			$filters[] = 'lowercase';
			$filters[] = 'stop';
			$filters[] = 'kstem';
			$filters[] = 'asciifolding';
			$config[ 'analyzer' ][ 'text' ][ 'filter' ] = $filters;

			// Add asciifolding to the the text_plain analyzer as well
			$config[ 'analyzer' ][ 'plain' ][ 'filter' ][] = 'asciifolding';
			// Add asciifolding to the prefix queries and incategory filters
			$config[ 'analyzer' ][ 'prefix' ][ 'filter' ][] = 'asciifolding';
			$config[ 'analyzer' ][ 'lowercase_keyword' ][ 'filter' ][] = 'asciifolding';
			break;
		case 'tr':
			$config[ 'filter' ][ 'lowercase' ][ 'language' ] = 'turkish';
			break;
		}
		return $config;
	}

	/**
	 * Pick the appropriate default analyzer based on the language.  Rather than think of
	 * this as per language customization you should think of this as an effort to pick a
	 * reasonably default in case CirrusSearch isn't customized for the language.
	 * @return string the analyzer type
	 */
	private function getDefaultTextAnalyzerType() {
		if ( array_key_exists( $this->language, $this->elasticsearchLanguageAnalyzers ) ) {
			return $this->elasticsearchLanguageAnalyzers[ $this->language ];
		} else {
			return 'default';
		}
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
		'zh' => 'chinese',
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
}
