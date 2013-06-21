<?php
/**
 * Config builder that returns a string for types and sets up all the required files for the
 * types it declares.
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
class TypesBuilder extends ConfigBuilder {
	public function __construct($where) {
		parent::__construct($where);
	}

	public function build() {
		$languageIndependentTypes = $this->indent( $this->buildLanguageIndependentTypes() );
		$textType = $this->indent( $this->buildTextType( 'text_splitting', true ) );
		$spellType = $this->indent( $this->buildTextType( 'spell', false ) );
		return <<<XML
<types>
$languageIndependentTypes
$textType
$spellType
	<fieldType name="prefix" class="solr.TextField">
		<analyzer type="index">
			<tokenizer class="solr.LowerCaseTokenizerFactory"/>
			<!-- Note that I set the maxGramSize to huge so we can match whole titles.  Bad idea? -->
			<filter class="solr.EdgeNGramFilterFactory" minGramSize="1" maxGramSize="10000" side="front"/>
		</analyzer>
		<analyzer type="query">
			<tokenizer class="solr.LowerCaseTokenizerFactory"/>
		</analyzer>
	</fieldType>
</types>
XML;
	}

	private function buildLanguageIndependentTypes() {
		return <<<XML
<fieldType name="integer" class="solr.TrieIntField" precisionStep="0" />
<fieldType name="long" class="solr.TrieLongField" precisionStep="0" />
<fieldType name="id" class="solr.StrField" />
<fieldType name="triedate" class="solr.TrieDateField" precisionStep="6" positionIncrementGap="0"/>
XML;
	}

	private function buildTextType( $name, $stemming ) {
		global $wgLanguageCode;
		$type = <<<XML
<fieldType name="$name" class="solr.TextField" autoGeneratePhraseQueries="true">
XML;
		switch ($wgLanguageCode) {
			case 'en':
				$this->copyRawConfigFile( 'lang/stopwords_en.txt' );
				$type .= <<<XML
	<analyzer type="index">
		<tokenizer class="solr.WhitespaceTokenizerFactory"/>
		<filter class="solr.StopFilterFactory"
			ignoreCase="true"
			words="lang/stopwords_en.txt"
			enablePositionIncrements="true"
			/>
		<filter class="solr.WordDelimiterFilterFactory"
			generateWordParts="1"
			generateNumberParts="1"
			catenateWords="1"
			catenateNumbers="1"
			catenateAll="0"
			splitOnCaseChange="1"/>
		<filter class="solr.LowerCaseFilterFactory"/>
XML;
				if ( $stemming ) {
					$type .= <<<XML
		<filter class="solr.PorterStemFilterFactory"/>
XML;
				}
				$type .= <<<XML
		<filter class="solr.ASCIIFoldingFilterFactory"/>
	</analyzer>
	<analyzer type="query">
		<tokenizer class="solr.WhitespaceTokenizerFactory"/>
		<filter class="solr.StopFilterFactory"
			ignoreCase="true"
			words="lang/stopwords_en.txt"
			enablePositionIncrements="true"
			/>
		<filter class="solr.WordDelimiterFilterFactory"
			generateWordParts="1"
			generateNumberParts="1"
			catenateWords="0"
			catenateNumbers="0"
			catenateAll="0"
			splitOnCaseChange="1"/>
		<filter class="solr.LowerCaseFilterFactory"/>
XML;
				if ( $stemming ) {
					$type .= <<<XML
		<filter class="solr.PorterStemFilterFactory"/>
XML;
				}
				$type .= <<<XML
		<filter class="solr.ASCIIFoldingFilterFactory"/>
	</analyzer>
XML;
				break;
			case 'ja':
				$this->copyRawConfigFile( 'lang/stoptags_ja.txt' );
				$this->copyRawConfigFile( 'lang/stopwords_ja.txt' );
				$type .= <<<XML
	<analyzer>
		<!-- Kuromoji Japanese morphological analyzer/tokenizer (JapaneseTokenizer) -->
		<tokenizer class="solr.JapaneseTokenizerFactory" mode="search"/>
		<!-- Reduces inflected verbs and adjectives to their base/dictionary forms (辞書形) -->
		<filter class="solr.JapaneseBaseFormFilterFactory"/>
		<!-- Removes tokens with certain part-of-speech tags -->
		<filter class="solr.JapanesePartOfSpeechStopFilterFactory" tags="lang/stoptags_ja.txt" enablePositionIncrements="true"/>
		<!-- Normalizes full-width romaji to half-width and half-width kana to full-width (Unicode NFKC subset) -->
		<filter class="solr.CJKWidthFilterFactory"/>
		<!-- Removes common tokens typically not useful for search, but have a negative effect on ranking -->
		<filter class="solr.StopFilterFactory" ignoreCase="true" words="lang/stopwords_ja.txt" enablePositionIncrements="true" />
		<!-- Normalizes common katakana spelling variations by removing any last long sound character (U+30FC) -->
XML;
				if ( $stemming ) {
					$type .= <<<XML
		<filter class="solr.JapaneseKatakanaStemFilterFactory" minimumLength="4"/>
XML;
				}
				$type .= <<<XML
		<!-- Lower-cases romaji characters -->
		<filter class="solr.LowerCaseFilterFactory"/>
	</analyzer>
XML;
				break;
			default:
				throw new Exception("Unknown language code:  $wgLanguageCode");
		}
		$type .= <<<XML
</fieldType>
XML;
		return $type;
	}
}
