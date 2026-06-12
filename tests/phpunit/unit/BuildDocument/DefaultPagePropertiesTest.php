<?php

namespace CirrusSearch\BuildDocument;

use CirrusSearch\Search\CirrusIndexField;
use Elastica\Document;
use MediaWiki\Content\Content;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Page\WikiPage;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Tests\Unit\DummyServicesTrait;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleValue;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * @covers \CirrusSearch\BuildDocument\DefaultPageProperties
 */
class DefaultPagePropertiesTest extends \MediaWikiUnitTestCase {
	use DummyServicesTrait;

	public function testPrimaryDocumentFields() {
		$doc = $this->buildDoc(
			$this->mockPage( NS_CATEGORY, 'Page_Name', 2 ),
			$this->mockRevision( '20220902130506' ),
			false
		);

		$this->assertEquals( [
			'wiki' => '',
			'page_id' => 2,
			'namespace' => NS_CATEGORY,
			'namespace_text' => 'Category',
			'timestamp' => '2022-09-02T13:05:06Z',
			'title' => 'Page Name',
			'page_type' => 'primary',
			'redirect_target' => null,
		], $doc->getData() );
		// redirect_target carries the equals noop handler on every document so a
		// redirect->primary conversion replaces (and here clears) the field as a unit.
		$this->assertSame( 'equals', $this->noopHandlerFor( $doc, 'redirect_target' ) );
	}

	public function testRedirectDocumentFields() {
		$doc = $this->buildDoc(
			$this->mockPage( NS_MAIN, 'Some Redirect', 5 ),
			$this->mockRevision( '20220902130506',
				$this->redirectContent( new TitleValue( NS_MAIN, 'Real Target' ) ) ),
			true
		);

		$this->assertSame( 'redirect', $doc->get( 'page_type' ) );
		$this->assertEquals( [
			'namespace' => NS_MAIN,
			'title' => 'Real Target',
			'fragment' => '',
			'interwiki' => '',
			'link' => 'Real Target',
		], $doc->get( 'redirect_target' ) );
		$this->assertSame( 'equals', $this->noopHandlerFor( $doc, 'redirect_target' ) );
	}

	public function testRedirectDocumentWithMalformedTargetClearsField() {
		$doc = $this->buildDoc(
			$this->mockPage( NS_MAIN, 'Broken', 6 ),
			$this->mockRevision( '20220902130506', $this->redirectContent( null ) ),
			true
		);

		$this->assertSame( 'redirect', $doc->get( 'page_type' ) );
		$this->assertNull( $doc->get( 'redirect_target' ) );
	}

	public function testRedirectDocumentWithInaccessibleContentClearsField() {
		// getContent() returns null for suppressed/corrupt content; the field is
		// cleared rather than dereferencing null.
		$doc = $this->buildDoc(
			$this->mockPage( NS_MAIN, 'Suppressed', 7 ),
			$this->mockRevision( '20220902130506', null ),
			true
		);

		$this->assertSame( 'redirect', $doc->get( 'page_type' ) );
		$this->assertNull( $doc->get( 'redirect_target' ) );
	}

	public static function provideRedirectTargetField() {
		return [
			'same-wiki' => [ new TitleValue( NS_MAIN, 'Target' ), [
				'namespace' => NS_MAIN, 'title' => 'Target', 'fragment' => '', 'interwiki' => '', 'link' => 'Target',
			] ],
			'fragment' => [ new TitleValue( NS_MAIN, 'Target', 'fragment' ), [
				'namespace' => NS_MAIN, 'title' => 'Target', 'fragment' => 'fragment', 'interwiki' => '', 'link' => 'Target#fragment',
			] ],
			'Special' => [ new TitleValue( NS_SPECIAL, 'BlankPage' ), [
				'namespace' => NS_SPECIAL, 'title' => 'BlankPage', 'fragment' => '', 'interwiki' => '', 'link' => 'Special:BlankPage',
			] ],
			'interwiki' => [ new TitleValue( NS_MAIN, 'Foo', '', 'en' ), [
				'namespace' => NS_MAIN, 'title' => 'Foo', 'fragment' => '', 'interwiki' => 'en', 'link' => 'en:Foo',
			] ],
		];
	}

	/**
	 * @dataProvider provideRedirectTargetField
	 */
	public function testRedirectTargetField( LinkTarget $target, array $expected ) {
		$this->assertEquals( $expected, $this->newProperties()->redirectTargetField( $target ) );
	}

	public function testRedirectTargetFieldMalformedReturnsNull() {
		$this->assertNull( $this->newProperties()->redirectTargetField( null ) );
	}

	private function mockPage( int $ns, string $dbkey, int $pageId ): WikiPage {
		$page = $this->createMock( WikiPage::class );
		$page->method( 'getTitle' )->willReturn( Title::makeTitle( $ns, $dbkey ) );
		$page->method( 'getId' )->willReturn( $pageId );
		return $page;
	}

	private function mockRevision( string $timestamp, ?Content $content = null ): RevisionRecord {
		$revision = $this->createMock( RevisionRecord::class );
		$revision->method( 'getTimestamp' )->willReturn( $timestamp );
		$revision->method( 'getContent' )->willReturn( $content );
		return $revision;
	}

	private function redirectContent( ?LinkTarget $target ): Content {
		$content = $this->createMock( Content::class );
		$content->method( 'getRedirectTarget' )->willReturn( $target );
		return $content;
	}

	private function noopHandlerFor( Document $doc, string $field ) {
		$hints = CirrusIndexField::getHint( $doc, CirrusIndexField::NOOP_HINT );
		return $hints[$field] ?? null;
	}

	private function newProperties(): DefaultPageProperties {
		// A real SelectQueryBuilder over a database whose only data method returns
		// false, so loadCreateTimestamp() finds no create timestamp. create_timestamp
		// behaviour itself is exercised by the integration test.
		$db = $this->createMock( IReadableDatabase::class );
		$db->method( 'newSelectQueryBuilder' )
			->willReturnCallback( static function () use ( $db ) {
				return new SelectQueryBuilder( $db );
			} );
		$db->method( 'selectField' )->willReturn( false );
		return new DefaultPageProperties( $db, $this->getDummyTitleFormatter() );
	}

	private function buildDoc( WikiPage $page, RevisionRecord $revision, bool $isRedirect ): Document {
		$props = $this->newProperties();
		$doc = new Document( '', [] );
		$props->initialize( $doc, $page, $revision, $isRedirect );
		$props->finishInitializeBatch();
		$props->finalize( $doc, $page->getTitle(), $revision );
		return $doc;
	}
}
