<?php
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
class CirrusSearchMappingConfigBuilder {
	/**
	 * @return array
	 */
	public static function build() {
		$builder = new CirrusSearchMappingConfigBuilder();
		return $builder->buildConfig();
	}

	/**
	 * Build the mapping config.
	 * @return array the mapping config
	 */
	public function buildConfig() {
		global $wgCirrusSearchPrefixSearchStartsWithAnyWord;
		global $wgCirrusSearchPhraseUseText;
		// Note never to set something as type='object' here because that isn't returned by elasticsearch
		// and is infered anyway.

		$titleExtraAnalyzers = array( 'suggest', 'prefix' );
		if ( $wgCirrusSearchPrefixSearchStartsWithAnyWord ) {
			$titleExtraAnalyzers[] = 'word_prefix';
		}

		$textExtraAnalyzers = array();
		if ( $wgCirrusSearchPhraseUseText ) {
			$textExtraAnalyzers[] = 'suggest';
		}

		return array(
			'properties' => array(
				'timestamp' => array(
					'type' => 'date',
					'format' => 'dateOptionalTime',
					'include_in_all' => false,
				),
				'namespace' => $this->buildLongField(),
				'title' => $this->buildStringField( 'title', $titleExtraAnalyzers ),
				'text' => $this->buildStringField( 'text', $textExtraAnalyzers ),
				'category' => $this->buildLowercaseKeywordField(),
				'outgoing_link' => $this->buildKeywordField(),
				'heading' => $this->buildStringField( 'heading' ),
				'text_bytes' => $this->buildLongField(),
				'text_words' => $this->buildLongField(),
				'redirect' => array(
					'properties' => array(
						'namespace' =>  $this->buildLongField(),
						'title' => $this->buildStringField( 'title', array( 'suggest' ) ),
					)
				),
				'incoming_links' => $this->buildLongField(),
				'incoming_redirect_links' => $this->buildLongField(),
			),
		);
	}

	/**
	 * Build a string field that does standard analysis for the language.
	 * @param $name string|null Name of the field.
	 * @param $extra array|null Extra analyzers for this field beyond the basic text and plain.
	 * @return array definition of the field
	 */
	private function buildStringField( $name, $extra = array() ) {
		$field = array(
			'type' => 'multi_field',
			'fields' => array(
				$name => array(
					'type' => 'string',
					'analyzer' => 'text',
					'store' => 'yes',
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
		foreach ( $extra as $extraname ) {
			$field[ 'fields' ][ $extraname ] = array(
				'type' => 'string',
				'analyzer' => $extraname,
				'include_in_all' => false,
			);
		}
		return $field;
	}

	/**
	 * Create a string field that only lower cases and does ascii folding (if enabled) for the language.
	 * @return array definition of the field
	 */
	private function buildLowercaseKeywordField() {
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
	private function buildKeywordField() {
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
	private function buildLongField() {
		return array(
			'type' => 'long',
			'store' => 'yes',
			'include_in_all' => false,
		);
	}
}
