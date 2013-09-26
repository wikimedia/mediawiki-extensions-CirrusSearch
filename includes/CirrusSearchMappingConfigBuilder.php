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
		// Note never to set something as type='object' here because that isn't returned by elasticsearch
		// and is infered anyway.
		return array(
			'properties' => array(
				'title' => $this->buildStringField( 'title', array( 'suggest', 'prefix' ) ),
				'text' => $this->buildStringField( 'text', array( 'suggest' ) ),
				'category' => $this->buildLowercaseKeywordField(),
				'heading' => $this->buildStringField( 'heading' ),
				'redirect' => array(
					'properties' => array(
						'title' => $this->buildStringField( 'title', array( 'suggest' ) ),
					)
				),
				'links' => array(
					'type' => 'integer',
					'store' => 'yes',
				),
				'redirect_links' => array(
					'type' => 'integer',
					'store' => 'yes',
				)
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
				),
				'plain' => array(
					'type' => 'string',
					'analyzer' => 'plain',
					'term_vector' => 'with_positions_offsets',
				),
			)
		);
		foreach ( $extra as $extraname ) {
			$field[ 'fields' ][ $extraname ] = array( 'type' => 'string', 'analyzer' => $extraname );
		}
		return $field;
	}

	/**
	 * Create a string field that only lower cases and does ascii folding (if enabled) for the language.
	 * @return array definition of the field
	 */
	private function buildLowercaseKeywordField() {
		return array( 'type' => 'string', 'analyzer' => 'lowercase_keyword' );
	}

}
