<?php

namespace CirrusSearch;

/**
 * Builds elasticsearch mapping configuration arrays.
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
class MappingConfigBuilder {
	/**
	 * Whether to allow prefix searches to match on any word
	 * @var bool
	 */
	private $prefixSearchStartsWithAnyWord;

	/**
	 * Whether phrase searches should use the suggestion analyzer
	 * @var bool
	 */
	private $phraseUseText;

	/**
	 * Constructor
	 * @param bool $anyWord Prefix search on any word
	 * @param bool $useText Text uses suggestion analyzer
	 */
	public function __construct( $anyWord, $useText ) {
		$this->prefixSearchStartsWithAnyWord = $anyWord;
		$this->phraseUseText = $useText;
	}

	/**
	 * Build the mapping config.
	 * @return array the mapping config
	 */
	public function buildConfig() {
		// Note never to set something as type='object' here because that isn't returned by elasticsearch
		// and is infered anyway.
		$titleExtraAnalyzers = array(
			'suggest',
			array( 'index' => 'prefix', 'search' => 'near_match' ),
			'near_match',
			'keyword',
		);
		if ( $this->prefixSearchStartsWithAnyWord ) {
			$titleExtraAnalyzers[] = array( 'index' => 'word_prefix', 'search' => 'plain' );
		}

		$textExtraAnalyzers = array();
		if ( $this->phraseUseText ) {
			$textExtraAnalyzers[] = 'suggest';
		}

		$config = array(
			'dynamic' => false,
			'properties' => array(
				'timestamp' => array(
					'type' => 'date',
					'format' => 'dateOptionalTime',
					'include_in_all' => false,
				),
				'namespace' => $this->buildLongField(),
				'title' => $this->buildStringField( 'title', $titleExtraAnalyzers ),
				'text' => array_merge_recursive(
					$this->buildStringField( 'text', $textExtraAnalyzers ),
					array( 'fields' => array( 'word_count' => array(
						'type' => 'token_count',
						'store' => 'yes',
						'analyzer' => 'plain',
						'include_in_all' => false,
					) ) )
				),
				'file_text' => $this->buildStringField( 'file_text', $textExtraAnalyzers ),
				'category' => $this->buildLowercaseKeywordField(),
				'template' => $this->buildLowercaseKeywordField(),
				'outgoing_link' => $this->buildKeywordField(),
				'external_link' => $this->buildKeywordField(),
				'heading' => $this->buildStringField( 'heading' ),
				'text_bytes' => $this->buildLongField(),
				'text_words' => $this->buildLongField(),      // TODO remove once text.word_count is available everywhere
				'redirect' => array(
					'dynamic' => false,
					'properties' => array(
						'namespace' =>  $this->buildLongField(),
						'title' => $this->buildStringField( 'title', array( 'suggest' ) ),
					)
				),
				'incoming_links' => $this->buildLongField(),
				'incoming_redirect_links' => $this->buildLongField(),
				'local_sites_with_dupe' => $this->buildLowercaseKeywordField(),
			),
		);
		wfRunHooks( 'CirrusSearchMappingConfig', array( &$config, $this ) );
		return $config;
	}

	/**
	 * Build a string field that does standard analysis for the language.
	 * @param $name string|null Name of the field.
	 * @param $extra array|null Extra analyzers for this field beyond the basic text and plain.
	 * @return array definition of the field
	 */
	public function buildStringField( $name, $extra = array() ) {
		$field = array(
			'type' => 'multi_field',
			'fields' => array(
				$name => array(
					'type' => 'string',
					'analyzer' => 'text',
					'term_vector' => 'with_positions_offsets',
					'include_in_all' => false,
				),
				'plain' => array(
					'type' => 'string',
					'analyzer' => 'plain',
					'term_vector' => 'with_positions_offsets',
					'include_in_all' => false,
				),
			)
		);
		foreach ( $extra as $extraName ) {
			if ( is_array( $extraName ) ) {
				$searchAnalyzer = $extraName[ 'search' ];
				$indexAnalyzer = $extraName[ 'index' ];
				$extraName = $indexAnalyzer;
			} else {
				$searchAnalyzer = $extraName;
				$indexAnalyzer = $extraName;
			}
			$field[ 'fields' ][ $extraName ] = array(
				'type' => 'string',
				'search_analyzer' => $searchAnalyzer,
				'index_analyzer' => $indexAnalyzer,
				'include_in_all' => false,
			);
		}
		return $field;
	}

	/**
	 * Create a string field that only lower cases and does ascii folding (if enabled) for the language.
	 * @return array definition of the field
	 */
	public function buildLowercaseKeywordField() {
		return array(
			'type' => 'string',
			'analyzer' => 'lowercase_keyword',
			'include_in_all' => false,
		);
	}

	/**
	 * Create a string field that does no analyzing whatsoever.
	 * @return array definition of the field
	 */
	public function buildKeywordField() {
		return array(
			'type' => 'string',
			'analyzer' => 'keyword',
			'include_in_all' => false,
		);
	}

	/**
	 * Create a long field.
	 * @return array definition of the field
	 */
	public function buildLongField() {
		return array(
			'type' => 'long',
			'include_in_all' => false,
		);
	}
}
