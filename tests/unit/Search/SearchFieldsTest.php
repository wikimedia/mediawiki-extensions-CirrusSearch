<?php

namespace CirrusSearch\Search;

use CirrusSearch\CirrusTestCase;
use SearchIndexField;

/**
 * @group CirrusSearch
 */
class SearchFieldsTest extends CirrusTestCase {

	public function getFields() {
		return [
			[ SearchIndexField::INDEX_TYPE_TEXT, 'text' ],
			[ SearchIndexField::INDEX_TYPE_KEYWORD, 'text' ],
			[ SearchIndexField::INDEX_TYPE_INTEGER, 'long' ],
			[ SearchIndexField::INDEX_TYPE_NUMBER, 'double' ],
			[ SearchIndexField::INDEX_TYPE_DATETIME, 'date' ],
			[ SearchIndexField::INDEX_TYPE_BOOL, 'boolean' ],
			[ SearchIndexField::INDEX_TYPE_NESTED, 'nested' ],
		];
	}

	/**
	 * @dataProvider getFields
	 * @param int    $type Generic type
	 * @param string $elasticType Elasticsearch type
	 */
	public function testFields( $type, $elasticType ) {
		$engine = new \CirrusSearch();
		$field = $engine->makeSearchFieldMapping( 'testField-' . $type, $type );
		$this->assertInstanceOf( CirrusIndexField::class, $field );
		$mapping = $field->getMapping( $engine );
		$this->assertEquals( $elasticType, $mapping['type'] );

		$field->setFlag( SearchIndexField::FLAG_NO_INDEX );
		$mapping = $field->getMapping( $engine );
		$this->assertEquals( false, $mapping['index'] );
	}

	public function testBadField() {
		$engine = new \CirrusSearch();
		$field = $engine->makeSearchFieldMapping( 'testBadField', 42 );
		$this->assertInstanceOf( \NullIndexField::class, $field );
		$this->assertEquals( null, $field->getMapping( $engine ) );
	}
}
