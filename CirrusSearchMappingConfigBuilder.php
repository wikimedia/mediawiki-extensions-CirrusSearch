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
			'title' => $this->buildStringField( 'title', array( 'suggest', 'prefix' ) ),
			'text' => $this->buildStringField( 'text', array( 'suggest' ) ),
			'category' => $this->buildStringField(),
			'redirect' => array(
				'properties' => array(
					'title' => $this->buildStringField()
				)
			)
		);
	}

	/**
	 * Build a string field.
	 * @param name string Name of the field.  Required if extra is not falsy.
	 * @param extra array Extra analyzers for this field beyond the basic string type.  If not falsy the
	 *		field will be a multi_field.
	 * @return array definition of the field
	 */
	private function buildStringField( $name = null, $extra = null ) {
		$field = array( 'type' => 'string', 'analyzer' => 'text' );
		if ( !$extra ) {
			return $field;
		}
		$field = array(
			'type' => 'multi_field',
			'fields' => array(
				$name => $field
			)
		);
		foreach ( $extra as $extraname ) {
			$field['fields'][$extraname] = array( 'type' => 'string', 'analyzer' => $extraname );
		}
		return $field;
	}

}