<?php

namespace CirrusSearch;

use CirrusSearch\Search\BooleanIndexField;
use CirrusSearch\Search\DatetimeIndexField;
use CirrusSearch\Search\IntegerIndexField;
use CirrusSearch\Search\KeywordIndexField;
use CirrusSearch\Search\NestedIndexField;
use CirrusSearch\Search\NumberIndexField;
use CirrusSearch\Search\TextIndexField;
use MediaWiki\MediaWikiServices;
use MediaWiki\Search\SearchIndexField;

/**
 * @group CirrusSearch
 * FIXME: what is this class actually testing? Can't cover interfaces.
 * @coversNothing
 */
class IndexFieldsTest extends CirrusIntegrationTestCase {

	public static function provideTypes() {
		return [
			[ SearchIndexField::INDEX_TYPE_TEXT, 'text', TextIndexField::class ],
			[ SearchIndexField::INDEX_TYPE_KEYWORD, 'keyword', KeywordIndexField::class ],
			[ SearchIndexField::INDEX_TYPE_INTEGER, 'long', IntegerIndexField::class ],
			[ SearchIndexField::INDEX_TYPE_NUMBER, 'double', NumberIndexField::class ],
			[ SearchIndexField::INDEX_TYPE_DATETIME, 'date', DatetimeIndexField::class ],
			[ SearchIndexField::INDEX_TYPE_NESTED, 'nested', NestedIndexField::class ],
			[ SearchIndexField::INDEX_TYPE_BOOL, 'boolean', BooleanIndexField::class ],
		];
	}

	/**
	 * @dataProvider provideTypes
	 * @param int $type Field type
	 * @param string $typeName Internal type name
	 * @param string $klass Class name
	 */
	public function testFieldTypes( $type, $typeName, $klass ) {
		$config =
			MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'CirrusSearch' );
		$engine = new CirrusSearch();
		/**
		 * @var \CirrusSearch\Search\CirrusIndexField $idxField
		 */
		$idxField = new $klass( "test$typeName", $type, $config );
		$map = $idxField->getMapping( $engine );
		$this->assertEquals( $typeName, $map['type'] );
		$this->assertEquals( $type, $idxField->getIndexType() );
		$this->assertEquals( "test$typeName", $idxField->getName() );
	}

	/**
	 * @dataProvider provideTypes
	 * @param int $type Field type
	 * @param string $typeName Internal type name
	 * @param string $klass Class name
	 */
	public function testFieldEngine( $type, $typeName, $klass ) {
		$engine = new CirrusSearch();
		$field = $engine->makeSearchFieldMapping( "test$typeName", $type );
		$this->assertInstanceOf( $klass, $field );
		$this->assertEquals( $type, $field->getIndexType() );
		$this->assertEquals( "test$typeName", $field->getName() );
	}
}
