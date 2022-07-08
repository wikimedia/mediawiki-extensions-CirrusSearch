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
		$page = $this->mock( WikiPage::class );
		$title = $this->mock( Title::class );
		$page->method( 'getTitle' )
			->willReturn( $title );
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
		$db = $this->mock( IDatabase::class );
		$title = $this->mock( Title::class );
		$props = new DefaultPageProperties( $db );
		$doc = new Document( '', [] );
		$props->initialize( $doc, $page );
		$props->finishInitializeBatch( [ $page ] );
		$props->finalize( $doc, $title );
		return $doc;
	}

	private function mock( $class ) {
		return $this->getMockBuilder( $class )
			->disableOriginalConstructor()
			->getMock();
	}
}
