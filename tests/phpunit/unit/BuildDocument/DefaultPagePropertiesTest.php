<?php

namespace CirrusSearch\BuildDocument;

use Elastica\Document;
use Title;
use Wikimedia\Rdbms\IDatabase;
use WikiPage;

/**
 * @covers \CirrusSearch\BuildDocument\DefaultPageProperties
 */
class DefaultPagePropertiesTest extends \MediaWikiUnitTestCase {
	public function testExpectedFields() {
		$page = $this->createMock( WikiPage::class );
		$page->method( 'getTitle' )
			->willReturn( $this->createMock( Title::class ) );
		$page->method( 'getId' )
			->willReturn( 2 );
		$doc = $this->buildDoc( $page );

		$expectFields = [
			'wiki', 'namespace', 'namespace_text',
			'title', 'timestamp'
		];
		$haveFields = array_keys( $doc->getData() );
		sort( $expectFields );
		sort( $haveFields );
		$this->assertEquals( $expectFields, $haveFields );
	}

	private function buildDoc( WikiPage $page ): Document {
		$props = new DefaultPageProperties( $this->createMock( IDatabase::class ) );
		$doc = new Document( '', [] );
		$props->initialize( $doc, $page );
		$props->finishInitializeBatch( [ $page ] );
		$props->finalize( $doc, $this->createMock( Title::class ) );
		return $doc;
	}

}
