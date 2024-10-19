<?php

namespace CirrusSearch\BuildDocument;

use CirrusSearch\CirrusSearch;
use CirrusSearch\HashSearchConfig;
use Elastica\Document;
use MediaWiki\Content\ContentHandler;
use MediaWiki\Content\WikitextContent;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
use WikiPage;

/**
 * @group Database
 * @covers \CirrusSearch\BuildDocument\ParserOutputPageProperties
 */
class ParserOutputPagePropertiesTest extends \MediaWikiIntegrationTestCase {
	public function testFixAndFlagInvalidUTF8InSource() {
		$this->assertNotContains( 'CirrusSearchInvalidUTF8',
			ParserOutputPageProperties::fixAndFlagInvalidUTF8InSource(
				[ 'source_text' => 'valid' ], 1 )['template'] ?? [], 1 );
		$this->assertContains( 'Template:CirrusSearchInvalidUTF8',
			ParserOutputPageProperties::fixAndFlagInvalidUTF8InSource(
				[ 'source_text' => chr( 130 ) ], 1 )['template'] ?? [] );
	}

	public function testTruncateFileContent() {
		$doc = [ 'file_text' => 'e é e' ];
		$this->assertSame( $doc, ParserOutputPageProperties::truncateFileTextContent( -1, $doc ) );
		$this->assertSame( $doc, ParserOutputPageProperties::truncateFileTextContent( 100, $doc ) );
		$this->assertSame( [ 'file_text' => '' ], ParserOutputPageProperties::truncateFileTextContent( 0, $doc ) );
		$this->assertSame( [ 'file_text' => 'e ' ], ParserOutputPageProperties::truncateFileTextContent( 2, $doc ) );
		$this->assertSame( [ 'file_text' => 'e ' ], ParserOutputPageProperties::truncateFileTextContent( 3, $doc ) );
		$this->assertSame( [ 'file_text' => 'e é' ], ParserOutputPageProperties::truncateFileTextContent( 4, $doc ) );
	}

	public static function displayTitleProvider() {
		$mainTitle = Title::makeTitle( NS_MAIN, 'Phpunit' );
		self::forceTitleLang( $mainTitle, 'fr' );
		$talkTitle = Title::makeTitle( NS_TALK, 'Phpunit' );
		self::forceTitleLang( $talkTitle, 'fr' );
		return [
			'null when no display title is set' => [
				null, $mainTitle, false,
			],
			'null when display title matches normal title (ns_main)' => [
				null, $mainTitle, 'Phpunit',
			],
			'null when display title matches normal title without namespace prefix' => [
				null, $talkTitle, 'Phpunit',
			],
			'null when display title matches normal title in different case' => [
				null, $mainTitle, 'phpunit',
			],
			'null when display title matches normal ns:title' => [
				null, $talkTitle, 'talk:phpunit',
			],
			'null when display title has only extra html tags (ns_main)' => [
				null, $mainTitle, 'php<i>unit</i>',
			],
			'null when display title has only extra html tags (ns_talk)' => [
				null, $talkTitle, 'php<i>unit</i>',
			],
			'values different from title text are returned' => [
				'foo', $mainTitle, 'foo',
			],
			'strips html' => [
				'foo', $mainTitle, '<b>foo</b>',
			],
			'strips broken html' => [
				'foo', $mainTitle, 'fo<b>o',
			],
			'strips namespace if it matches doc namespace' => [
				'foo', $talkTitle, 'talk:foo',
			],
			'strips namespaces in the language of the document' => [
				'bar', $talkTitle, 'Discussion:bar',
			],
			'strips namespaces aliases as well' => [
				'bar', $talkTitle, 'Discuter:bar',
			],
			'ignores namespace case' => [
				'bar', $talkTitle, 'discuter:bar',
			],
			'null when only difference is translated namespace' => [
				null, $talkTitle, 'Discuter:<i>phpunit</i>',
			],
			'leaves non-namespaces in display title (ns_main)' => [
				'foo:bar', $mainTitle, 'foo:bar',
			],
			'leaves non-namespaces in display title (ns_talk)' => [
				'foo:bar', $talkTitle, 'foo:bar',
			],
			'leaves existing but unrelated namespaces in display title' => [
				'user:bar', $talkTitle, 'user:bar',
			],
			'invalid title is kept on NS_MAIN' => [
				':', $mainTitle, ':',
			],
			'invalid title is kept on non NS_MAIN' => [
				':', $talkTitle, ':',
			],
		];
	}

	private function buildDoc( WikiPage $page ) {
		$doc = new Document( null, [] );
		$builder = new ParserOutputPageProperties( new HashSearchConfig( [] ) );
		$builder->finalizeReal( $doc, $page, new CirrusSearch, $page->getRevisionRecord() );
		return $doc;
	}

	/**
	 * @dataProvider displayTitleProvider
	 */
	public function testDisplayTitle( $expected, Title $title, $displayTitle ) {
		$parserOutput = $this->createMock( ParserOutput::class );
		$parserOutput->method( 'getDisplayTitle' )
			->willReturn( $displayTitle );

		$page = $this->pageWithMockParserOutput( $title, $parserOutput );
		$doc = $this->buildDoc( $page );
		$this->assertTrue( $doc->has( 'display_title' ), 'field must exist' );
		$this->assertSame( $expected, $doc->get( 'display_title' ) );
	}

	public function testParserOutputUnavailable() {
		$title = Title::makeTitle( NS_MAIN, 'Phpunit' );
		$page = $this->pageWithMockParserOutput( $title, null );
		$this->expectException( BuildDocumentException::class );
		$this->expectExceptionMessage( "ParserOutput cannot be obtained." );
		$this->buildDoc( $page );
	}

	private function pageWithMockParserOutput( Title $title, ?ParserOutput $parserOutput ) {
		$contentHandler = $this->createMock( ContentHandler::class );
		$contentHandler->method( 'getParserOutputForIndexing' )
			->willReturn( $parserOutput );
		$contentHandler->method( 'getDataForSearchIndex' )
			->willReturn( [] );

		$page = $this->createMock( WikiPage::class );
		$page->method( 'getTitle' )
			->willReturn( $title );
		$page->method( 'getTouched' )
			->willReturn( '20230102110000' );
		$page->method( 'getContentHandler' )
			->willReturn( $contentHandler );
		$page->method( 'getContent' )
			->willThrowException( new \AssertionError( "getContent should not be called on the page" ) );
		$page->method( 'getId' )
			->willReturn( 2 );

		$revision = $this->createMock( RevisionRecord::class );
		$revision->method( 'getId' )
			->willReturn( 12 );
		$revision->method( 'getContent' )->willReturn( new WikitextContent( 'TEST_CONTENT' ) );
		$page->method( 'getRevisionRecord' )
			->willReturn( $revision );

		return $page;
	}

	private static function forceTitleLang( Title $title, $langCode ) {
		global $wgLanguageCode;
		$refl = new \ReflectionProperty( Title::class, 'mPageLanguage' );
		$refl->setAccessible( true );
		$refl->setValue( $title, [ $langCode, $wgLanguageCode ] );
	}
}
