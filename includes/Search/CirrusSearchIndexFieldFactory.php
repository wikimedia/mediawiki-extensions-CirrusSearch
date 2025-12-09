<?php

namespace CirrusSearch\Search;

use CirrusSearch\SearchConfig;
use NullIndexField;
use SearchIndexField;

/**
 * Create different types of SearchIndexFields.
 *
 * @license GPL-2.0-or-later
 */
class CirrusSearchIndexFieldFactory {

	/**
	 * @var SearchConfig
	 */
	private $searchConfig;

	public function __construct( SearchConfig $searchConfig ) {
		$this->searchConfig = $searchConfig;
	}

	/**
	 * Create a search field definition
	 * @param string $name
	 * @param string $type
	 * @return SearchIndexField
	 */
	public function makeSearchFieldMapping( $name, $type ): SearchIndexField {
		// Specific types
		switch ( $name ) {
			case 'opening_text':
				return new OpeningTextIndexField( $name, $type, $this->searchConfig );
			case 'template':
				return new KeywordIndexField( $name, $type, $this->searchConfig, true );
		}

		switch ( $type ) {
			case SearchIndexField::INDEX_TYPE_TEXT:
				return new TextIndexField( $name, $type, $this->searchConfig );
			case SearchIndexField::INDEX_TYPE_KEYWORD:
				return new KeywordIndexField( $name, $type, $this->searchConfig );
			case SearchIndexField::INDEX_TYPE_INTEGER:
				return new IntegerIndexField( $name, $type, $this->searchConfig );
			case SearchIndexField::INDEX_TYPE_NUMBER:
				return new NumberIndexField( $name, $type, $this->searchConfig );
			case SearchIndexField::INDEX_TYPE_DATETIME:
				return new DatetimeIndexField( $name, $type, $this->searchConfig );
			case SearchIndexField::INDEX_TYPE_BOOL:
				return new BooleanIndexField( $name, $type, $this->searchConfig );
			case SearchIndexField::INDEX_TYPE_NESTED:
				return new NestedIndexField( $name, $type, $this->searchConfig );
			case SearchIndexField::INDEX_TYPE_SHORT_TEXT:
				return new ShortTextIndexField( $name, $type, $this->searchConfig );
		}

		return new NullIndexField();
	}

	/**
	 * Build a string field that does standard analysis for the language.
	 * @param string $fieldName
	 * @param int|null $options Field options:
	 *   ENABLE_NORMS: Enable norms on the field.  Good for text you search against but bad for array fields and useless
	 *     for fields that don't get involved in the score.
	 *   COPY_TO_SUGGEST: Copy the contents of this field to the suggest field for "Did you mean".
	 *   COPY_TO_SUGGEST_VARIANT: Copy the contents of this field to the secondary suggest field for ab testing
	 *     "Did you mean".
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
