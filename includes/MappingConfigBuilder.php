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
	 * Version number for the core analysis. Increment the major
	 * version when the analysis changes in an incompatible way,
	 * and change the minor version when it changes but isn't
	 * incompatible
	 */
	const VERSION = 0.1;

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
		$suggestExtra = array( 'analyzer' => 'suggest' );
		// Note never to set something as type='object' here because that isn't returned by elasticsearch
		// and is infered anyway.
		$titleExtraAnalyzers = array(
			$suggestExtra,
			array( 'index_analyzer' => 'prefix', 'search_analyzer' => 'near_match', 'index_options' => 'docs' ),
			array( 'analyzer' => 'near_match', 'index_options' => 'docs' ),
			array( 'analyzer' => 'keyword', 'index_options' => 'docs' ),
		);
		if ( $this->prefixSearchStartsWithAnyWord ) {
			$titleExtraAnalyzers[] = array(
				'index_analyzer' => 'word_prefix',
				'search_analyzer' => 'plain',
				'index_options' => 'docs'
			);
		}

		$textExtraAnalyzers = array();
		if ( $this->phraseUseText ) {
			$textExtraAnalyzers[] = $suggestExtra;
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
				'heading' => $this->buildStringField( 'heading', array(), false ),
				'text_bytes' => $this->buildLongField(),
				'text_words' => $this->buildLongField(),      // TODO remove once text.word_count is available everywhere
				'redirect' => array(
					'dynamic' => false,
					'properties' => array(
						'namespace' =>  $this->buildLongField(),
						'title' => $this->buildStringField( 'title', array( $suggestExtra ), false ),
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
	 * @param string $name Name of the field.
	 * @param array|null $extra Extra analyzers for this field beyond the basic text and plain.
	 * @param boolean $enableNorms Should length norms be enabled for the field?  Defaults to true.
	 * @return array definition of the field
	 */
	public function buildStringField( $name, $extra = array(), $enableNorms = true ) {
		$norms = array( 'enabled' => $enableNorms );
		$field = array(
			'type' => 'multi_field',
			'fields' => array(
				$name => array(
					'type' => 'string',
					'analyzer' => 'text',
					'term_vector' => 'with_positions_offsets',
					'include_in_all' => false,
					'norms' => $norms,
				),
				'plain' => array(
					'type' => 'string',
					'analyzer' => 'plain',
					'term_vector' => 'with_positions_offsets',
					'include_in_all' => false,
					'norms' => $norms,
				),
			)
		);
		foreach ( $extra as $extraField ) {
			if ( isset( $extraField[ 'analyzer' ] ) ) {
				$extraName = $extraField[ 'analyzer' ];
			} else {
				$extraName = $extraField[ 'index_analyzer' ];
			}
			$field[ 'fields' ][ $extraName ] = array_merge( array(
				'type' => 'string',
				'include_in_all' => false,
				'norms' => $norms,
			), $extraField );
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
			'norms' => array( 'enabled' => false ),  // Omit the length norm because there is only even one token
			'index_options' => 'docs', // Omit the frequency and position information because neither are useful
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
			'norms' => array( 'enabled' => false ),  // Omit the length norm because there is only even one token
			'index_options' => 'docs', // Omit the frequency and position information because neither are useful
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
