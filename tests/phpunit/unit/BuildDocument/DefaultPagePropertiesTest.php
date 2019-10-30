<?php

namespace CirrusSearch\BuildDocument;

use Elastica\Document;
use IDatabase;
use Title;
use WikiPage;

/**
 * @covers \CirrusSearch\BuildDocument\DefaultPageProperties
 */
class DefaultPagePropertiesTest extends \MediaWikiUnitTestCase {
	public function testExpectedFields() {
		$page = $this->mock( WikiPage::class );
		$title = $this->mock( Title::class );
		$page->method( 'getTitle' )
			->will( $this->returnValue( $title ) );
		$page->method( 'getId' )
			->will( $this->returnValue( 2 ) );
		$doc = $this->buildDoc( $page );

		$expectFields = [
			'version', 'wiki', 'namespace', 'namespace_text',
			'title', 'timestamp'
		];
		$haveFields = array_keys( $doc->getData() );
		sort( $expectFields );
		sort( $haveFields );
		$this->assertEquals( $expectFields, $haveFields );
	}

	private function buildDoc( WikiPage $page ): Document {
		$db = $this->mock( IDatabase::class );
		$props = new DefaultPageProperties( $db );
		$doc = new Document( null, [] );
		$props->initialize( $doc, $page );
		$props->finishInitializeBatch( [ $page ] );
		return $doc;
	}

	private function mock( $class ) {
		return $this->getMockBuilder( $class )
			->disableOriginalConstructor()
			->getMock();
	}
}
