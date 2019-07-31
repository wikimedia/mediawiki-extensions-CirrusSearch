<?php

namespace CirrusSearch\BuildDocument;

use Elastica\Document;
use MediaWiki\Revision\RevisionRecord;
use Title;
use Wikimedia\Rdbms\IDatabase;
use WikiPage;

/**
 * @covers \CirrusSearch\BuildDocument\DefaultPageProperties
 */
class DefaultPagePropertiesTest extends \MediaWikiUnitTestCase {
	public function testExpectedFields() {
		$title = $this->createMock( Title::class );
		$title->method( 'getNsText' )
			->willReturn( 'Category' );
		$title->method( 'getNamespace' )
			->willReturn( NS_CATEGORY );
		$title->method( 'getText' )
			->willReturn( 'Page_Name' );
		$page = $this->createMock( WikiPage::class );
		$page->method( 'getTitle' )
			->willReturn( $title );
		$page->method( 'getId' )
			->willReturn( 2 );
		$revision = $this->createMock( RevisionRecord::class );
		$revision->method( 'getTimestamp' )->willReturn( "20220902130506" );
		$doc = $this->buildDoc( $page, $revision );

		$expectFields = [
			'wiki' => '',
			'page_id' => 2,
			'namespace' => NS_CATEGORY,
			'namespace_text' => 'Category',
			'timestamp' => "2022-09-02T13:05:06Z",
			'title' => 'Page_Name'
		];
		$this->assertEquals( $expectFields, $doc->getData() );
	}

	private function buildDoc( WikiPage $page, RevisionRecord $revision ): Document {
		$props = new DefaultPageProperties( $this->createMock( IDatabase::class ) );
		$doc = new Document( '', [] );
		$props->initialize( $doc, $page, $revision );
		$props->finishInitializeBatch();
		$props->finalize( $doc, $this->createMock( Title::class ), $revision );
		return $doc;
	}

}
