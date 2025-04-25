<?php

namespace CirrusSearch\BuildDocument;

use Elastica\Document;
use MediaWiki\Page\WikiPage;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;

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
		$queryBuilder = $this->createMock( SelectQueryBuilder::class );
		$queryBuilder->method( $this->logicalOr( 'select', 'from', 'where', 'orderBy', 'caller' ) )->willReturnSelf();
		$queryBuilder->method( 'fetchRow' )->willReturn( false );
		$database = $this->createMock( IReadableDatabase::class );
		$database->method( 'newSelectQueryBuilder' )
			->willReturn( $queryBuilder );
		$props = new DefaultPageProperties( $database );
		$doc = new Document( '', [] );
		$props->initialize( $doc, $page, $revision );
		$props->finishInitializeBatch();
		$props->finalize( $doc, $this->createMock( Title::class ), $revision );
		return $doc;
	}

}
