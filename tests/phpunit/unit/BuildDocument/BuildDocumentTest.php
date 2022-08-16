<?php

namespace CirrusSearch\BuildDocument;

use CirrusSearch\CirrusTestCaseTrait;
use CirrusSearch\Connection;
use CirrusSearch\SearchConfig;
use Elastica\Document;
use MediaWiki\Cache\BacklinkCacheFactory;
use MediaWiki\Revision\RevisionStore;
use ParserCache;
use Title;
use Wikimedia\Rdbms\IDatabase;
use WikiPage;

/**
 * @covers \CirrusSearch\BuildDocument\BuildDocument
 */
class BuildDocumentTest extends \MediaWikiUnitTestCase {
	use CirrusTestCaseTrait;

	private function mockBuilder( Title $title ) {
		// Would be nice if we could pass the makeId function instead of a whole SearchConfig
		$config = new SearchConfig;
		$connection = $this->createMock( Connection::class );
		$connection->method( 'getConfig' )
			->willReturn( $config );
		$revStore = $this->createMock( RevisionStore::class );
		$revStore->method( 'getTitle' )
			->willReturn( $title );

		return new class(
			$connection,
			$this->createMock( IDatabase::class ),
			$this->createMock( ParserCache::class ),
			$revStore,
			$this->createCirrusSearchHookRunner(),
			$this->createMock( BacklinkCacheFactory::class )
		) extends BuildDocument {
			// Override create builders to avoid testing those implementations
			protected function createBuilders( int $flags ): array {
				return [ new class() implements PagePropertyBuilder {
					private $doc;

					public function initialize( Document $doc, WikiPage $page ): void {
						$this->doc = $doc;
						$doc->set( 'phpunit_page_id', $page->getId() );
					}

					public function finishInitializeBatch(): void {
						$this->doc->set( 'phpunit_finish_batch', true );
					}

					public function finalize( Document $doc, Title $title ): void {
						$doc->set( 'phpunit_finalize', true );
					}
				} ];
			}
		};
	}

	public function testHappyPath() {
		$title = $this->createMock( Title::class );
		$title->method( 'getLatestRevID' )->willReturn( 42 );
		$pages = [];
		foreach ( range( 0, 1 ) as $pageId ) {
			$page = $this->createMock( WikiPage::class );
			$page->method( 'getId' )->willReturn( $pageId );
			$page->method( 'getTitle' )->willReturn( $title );
			$page->method( 'getLatest' )->willReturn( 42 );
			// $pageId == 0 does not exist
			$page->method( 'exists' )->willReturn( (bool)$pageId );
			$pages[] = $page;
		}

		$builder = $this->mockBuilder( $title );
		$docs = $builder->initialize(
			$pages, BuildDocument::INDEX_EVERYTHING
		);
		// non existent doc was thrown away
		$this->assertCount( 1, $docs );
		// doc has expected index of $pageId
		$this->assertArrayHasKey( 1, $docs );
		$doc = $docs[$pageId];
		$this->assertSame( 1, $doc->get( 'phpunit_page_id' ) );
		$this->assertTrue( $doc->get( 'phpunit_finish_batch' ) );

		$builder->finalize( $doc );
		$this->assertTrue( $doc->get( 'phpunit_finalize' ) );
	}

}
