<?php

namespace CirrusSearch\Search;

use CirrusSearch\CirrusTestCase;

/**
 * @group CirrusSearch
 */
class CirrusSearchIndexFieldFactoryTest extends CirrusTestCase {

	public function testNewStringField() {
		$searchConfig = $this->getSearchConfig();

		$factory = new CirrusSearchIndexFieldFactory( $searchConfig );
		$stringField = $factory->newStringField( 'title' );

		$this->assertInstanceOf( TextIndexField::class, $stringField );
		$this->assertSame( 'title', $stringField->getName(), 'field name is `title`' );
	}

	public function testNewLongField() {
		$searchConfig = $this->getSearchConfig();

		$factory = new CirrusSearchIndexFieldFactory( $searchConfig );
		$longField = $factory->newLongField( 'count' );

		$this->assertInstanceOf( IntegerIndexField::class, $longField );
		$this->assertSame( 'count', $longField->getName(), 'field name is `count`' );
	}

	public function testNewKeywordField() {
		$searchConfig = $this->getSearchConfig();

		$factory = new CirrusSearchIndexFieldFactory( $searchConfig );
		$keywordField = $factory->newKeywordField( 'id' );

		$this->assertInstanceOf( KeywordIndexField::class, $keywordField );
		$this->assertSame( 'id', $keywordField->getName(), 'field name is `id`' );
	}

	private function getSearchConfig() {
		return $this->getMockBuilder( 'CirrusSearch\SearchConfig' )
			->getMock();
	}

}
