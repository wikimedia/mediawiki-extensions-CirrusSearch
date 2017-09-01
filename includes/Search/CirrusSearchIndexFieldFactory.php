<?php

namespace CirrusSearch\Search;

use CirrusSearch\SearchConfig;
use Exception;
use NullIndexField;
use SearchIndexField;

/**
 * Create different types of SearchIndexFields.
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
class CirrusSearchIndexFieldFactory {

	/**
	 * @var SearchConfig
	 */
	private $searchConfig;

	/**
	 * @param SearchConfig $searchConfig
	 */
	public function __construct( SearchConfig $searchConfig ) {
		$this->searchConfig = $searchConfig;
	}

	/**
	 * Create a search field definition
	 * @param string $name
	 * @param int $type
	 * @throws Exception
	 * @return SearchIndexField
	 */
	public function makeSearchFieldMapping( $name, $type ) {
		$overrides = $this->searchConfig->get( 'CirrusSearchFieldTypeOverrides' );
		$mappings = $this->searchConfig->get( 'CirrusSearchFieldTypes' );
		if ( !isset( $mappings[$type] ) ) {
			return new NullIndexField();
		}
		$klass = $mappings[$type];

		// Check if a specific class is provided for this field
		if ( isset( $overrides[$name] ) ) {
			if ( $klass !== $overrides[$name] && !is_subclass_of( $overrides[$name], $klass ) ) {
				throw new Exception( "Specialized class " . $overrides[$name] .
					" for field $name is not compatible with type class $klass" );
			}
			$klass = $overrides[$name];
		}

		return new $klass( $name, $type, $this->searchConfig );
	}

	/**
	 * Build a string field that does standard analysis for the language.
	 * @param string $fieldName the field name
	 * @param int $options Field options:
	 *   ENABLE_NORMS: Enable norms on the field.  Good for text you search against but bad for array fields and useless
	 *     for fields that don't get involved in the score.
	 *   COPY_TO_SUGGEST: Copy the contents of this field to the suggest field for "Did you mean".
	 *   SPEED_UP_HIGHLIGHTING: Store extra data in the field to speed up highlighting.  This is important for long
	 *     strings or fields with many values.
	 * @param array $extra Extra analyzers for this field beyond the basic text and plain.
	 * @return TextIndexField definition of the field
	 */
	public function newStringField( $fieldName, $options = null, $extra = [] ) {
		$field = new TextIndexField(
			$fieldName,
			SearchIndexField::INDEX_TYPE_TEXT,
			$this->searchConfig,
			$extra
		);

		$field->setTextOptions( $options );

		return $field;
	}

	/**
	 * Create a long field.
	 * @param string $name Field name
	 * @return IntegerIndexField
	 */
	public function newLongField( $name ) {
		return new IntegerIndexField(
			$name,
			SearchIndexField::INDEX_TYPE_INTEGER,
			$this->searchConfig
		);
	}

	/**
	 * Create a long field.
	 * @param string $name Field name
	 * @return KeywordIndexField
	 */
	public function newKeywordField( $name ) {
		return new KeywordIndexField(
			$name,
			SearchIndexField::INDEX_TYPE_KEYWORD,
			$this->searchConfig
		);
	}
}
