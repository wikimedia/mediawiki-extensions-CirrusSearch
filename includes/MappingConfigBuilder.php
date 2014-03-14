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
	// Bit field parameters for buildStringField.
	const MINIMAL = 0;
	const ENABLE_NORMS = 1;
	const COPY_TO_SUGGEST = 2;

	/**
	 * Version number for the core analysis. Increment the major
	 * version when the analysis changes in an incompatible way,
	 * and change the minor version when it changes but isn't
	 * incompatible
	 */
	const VERSION = 0.4;

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
		$textOptions = MappingConfigBuilder::ENABLE_NORMS;
		if ( $this->phraseUseText ) {
			$textExtraAnalyzers[] = $suggestExtra;
			$textOptions |= MappingConfigBuilder::COPY_TO_SUGGEST;
		}

		$config = array(
			'dynamic' => false,
			'_all' => array( 'enabled' => false ),
			'properties' => array(
				'timestamp' => array(
					'type' => 'date',
					'format' => 'dateOptionalTime',
				),
				'namespace' => $this->buildLongField(),
				'namespace_text' => $this->buildKeywordField(),
				'title' => $this->buildStringField( 'title',
					MappingConfigBuilder::ENABLE_NORMS | MappingConfigBuilder::COPY_TO_SUGGEST,
					$titleExtraAnalyzers ),
				'text' => array_merge_recursive(
					$this->buildStringField( 'text', $textOptions, $textExtraAnalyzers ),
					array( 'fields' => array( 'word_count' => array(
						'type' => 'token_count',
						'store' => true,
						'analyzer' => 'plain',
					) ) )
				),
				'file_text' => $this->buildStringField( 'file_text', $textOptions ),
				'category' => $this->buildLowercaseKeywordField(),
				'template' => $this->buildLowercaseKeywordField(),
				'outgoing_link' => $this->buildKeywordField(),
				'external_link' => $this->buildKeywordField(),
				'heading' => $this->buildStringField( 'heading', MappingConfigBuilder::MINIMAL ),
				'text_bytes' => $this->buildLongField( false ),
				'redirect' => array(
					'dynamic' => false,
					'properties' => array(
						'namespace' =>  $this->buildLongField(),
						'title' => $this->buildStringField( 'title', MappingConfigBuilder::COPY_TO_SUGGEST,
							$titleExtraAnalyzers ),
					)
				),
				'incoming_links' => $this->buildLongField(),
				'local_sites_with_dupe' => $this->buildLowercaseKeywordField(),
				'suggest' => array(
					'type' => 'string',
					'analyzer' => 'suggest',
				)
			),
		);
		wfRunHooks( 'CirrusSearchMappingConfig', array( &$config, $this ) );
		return $config;
	}

	/**
	 * Build a string field that does standard analysis for the language.
	 * @param string $name Name of the field.
	 * @param array|null $extra Extra analyzers for this field beyond the basic text and plain.
	 * @param int $options Field options:
	 *   ENABLE_NORMS: Gnable norms on the field.  Good for text you search against but bad for array fields and useless
	 *     for fields that don't get involved in the score.
	 *   COPY_TO_SUGGEST: Copy the contents of this field to the suggest field for "Did you mean".
	 * @return array definition of the field
	 */
	public function buildStringField( $name, $options, $extra = array() ) {
		// multi_field is dead in 1.0 so we do this which actually looks less gnarly.
		$field = array(
			'type' => 'string',
			'analyzer' => 'text',
			'term_vector' => 'with_positions_offsets',
			'fields' => array(
				'plain' => array(
					'type' => 'string',
					'analyzer' => 'plain',
					'term_vector' => 'with_positions_offsets',
				),
			)
		);
		$disableNorms = ( $options & MappingConfigBuilder::ENABLE_NORMS ) === 0;
		if ( $disableNorms ) {
			$disableNorms = array( 'norms' => array( 'enabled' => false ) );
			$field = array_merge( $field, $disableNorms );
			$field[ 'fields' ][ 'plain' ] = array_merge( $field[ 'fields' ][ 'plain' ], $disableNorms );
		}
		if ( $options & MappingConfigBuilder::COPY_TO_SUGGEST ) {
			$field[ 'copy_to' ] = array( 'suggest' );
		}
		foreach ( $extra as $extraField ) {
			if ( isset( $extraField[ 'analyzer' ] ) ) {
				$extraName = $extraField[ 'analyzer' ];
			} else {
				$extraName = $extraField[ 'index_analyzer' ];
			}
			$field[ 'fields' ][ $extraName ] = array_merge( array(
				'type' => 'string',
			), $extraField );
			if ( $disableNorms ) {
				$field[ 'fields' ][ $extraName ] = array_merge(
					$field[ 'fields' ][ $extraName ], $disableNorms );
			}
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
			'norms' => array( 'enabled' => false ),  // Omit the length norm because there is only even one token
			'index_options' => 'docs', // Omit the frequency and position information because neither are useful
		);
	}

	/**
	 * Create a long field.
	 * @param boolean $index should this be indexed
	 * @return array definition of the field
	 */
	public function buildLongField( $index = true ) {
		$config = array(
			'type' => 'long',
		);
		if ( !$index ) {
			$config[ 'index' ] = 'no';
		}
		return $config;
	}
}
