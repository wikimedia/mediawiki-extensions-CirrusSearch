<?php

namespace CirrusSearch\Search;

use CirrusSearch\CirrusTestCase;
use SearchIndexField;

/**
 * @group CirrusSearch
 * @covers \CirrusSearch\Search\CirrusIndexField
 */
class SearchFieldsTest extends CirrusTestCase {

	public static function provideFields() {
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
	 * @dataProvider provideFields
	 * @param int $type Generic type
	 * @param string $elasticType Elasticsearch type
	 */
	public function testFields( $type, $elasticType ) {
		$engine = $this->newEngine();
		$field = $engine->makeSearchFieldMapping( 'testField-' . $type, $type );
		$this->assertInstanceOf( CirrusIndexField::class, $field );
		$mapping = $field->getMapping( $engine );
		$this->assertEquals( $elasticType, $mapping['type'] );

		$field->setFlag( SearchIndexField::FLAG_NO_INDEX );
		$mapping = $field->getMapping( $engine );
		$this->assertFalse( $mapping['index'] );
	}

	public function testBadField() {
		$engine = $this->newEngine();
		$field = $engine->makeSearchFieldMapping( 'testBadField', 42 );
		$this->assertInstanceOf( \NullIndexField::class, $field );
		$this->assertNull( $field->getMapping( $engine ) );
	}

	public function testHints() {
		$doc = new \Elastica\Document( 1, [] );
		$hint = CirrusIndexField::getHint( $doc, CirrusIndexField::NOOP_HINT );
		$this->assertNull( $hint );

		CirrusIndexField::addNoopHandler( $doc, "foo", "bar" );
		$this->assertTrue( $doc->hasParam( CirrusIndexField::DOC_HINT_PARAM ) );
		$this->assertArrayHasKey( CirrusIndexField::NOOP_HINT, $doc->getParam( CirrusIndexField::DOC_HINT_PARAM ) );
		$hint = CirrusIndexField::getHint( $doc, CirrusIndexField::NOOP_HINT );
		$this->assertEquals( [ "foo" => "bar" ], $hint );

		CirrusIndexField::resetHints( $doc );
		$hint = CirrusIndexField::getHint( $doc, CirrusIndexField::NOOP_HINT );
		$this->assertNull( $hint );
		$this->assertFalse( $doc->hasParam( CirrusIndexField::DOC_HINT_PARAM ) );

		CirrusIndexField::addNoopHandler( $doc, "foo", CirrusIndexField::MULTILIST_HANDLER );
		$doc->set( "foo", "test/" . CirrusIndexField::MULTILIST_DELETE_GROUPING );
		CirrusIndexField::resetHints( $doc );
		$this->assertSame( [], $doc->get( "foo" ) );

		CirrusIndexField::addNoopHandler( $doc, "foo", CirrusIndexField::MULTILIST_HANDLER );
		$doc->set( "foo", [ "test/" . CirrusIndexField::MULTILIST_DELETE_GROUPING, "other/to_keep" ] );
		CirrusIndexField::resetHints( $doc );
		$this->assertSame( [ "other/to_keep" ], $doc->get( "foo" ) );
	}

	public function testHintsRoundTrip() {
		$doc = new \Elastica\Document( 1, [] );
		// Fetching unknown hint returns null
		$hint = CirrusIndexField::getHint( $doc, 'unset-hint' );
		$this->assertNull( $hint );

		// Can set hint directly and fetch it back
		CirrusIndexField::setHint( $doc, CirrusIndexField::NOOP_HINT, [ 'foo' => 'bar' ] );
		$hint = CirrusIndexField::getHint( $doc, CirrusIndexField::NOOP_HINT );
		$this->assertEquals( [ "foo" => "bar" ], $hint );

		// Setting unrelated hints doesn't change previously set hint
		CirrusIndexField::setHint( $doc, 'arbitrary', [ 'foo' => 'bar' ] );
		$hint = CirrusIndexField::getHint( $doc, CirrusIndexField::NOOP_HINT );
		$this->assertEquals( [ "foo" => "bar" ], $hint );
	}
}
