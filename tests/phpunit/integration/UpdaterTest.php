<?php

namespace CirrusSearch;

use CirrusSearch;
use ContentHandler;
use ParserOutput;
use Revision;
use Title;
use WikiPage;

/**
 * @group Database
 * @covers \CirrusSearch\Updater
 */
class UpdaterTest extends \MediaWikiIntegrationTestCase {

	public function displayTitleProvider() {
		$mainTitle = Title::makeTitle( NS_MAIN, 'Phpunit' );
		$this->forceTitleLang( $mainTitle, 'fr' );
		$talkTitle = Title::makeTitle( NS_TALK, 'Phpunit' );
		$this->forceTitleLang( $talkTitle, 'fr' );
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

	/**
	 * @dataProvider displayTitleProvider
	 */
	public function testDisplayTitle( $expected, Title $title, $displayTitle ) {
		$parserOutput = $this->mock( ParserOutput::class );
		$parserOutput->expects( $this->any() )
			->method( 'getDisplayTitle' )
			->will( $this->returnValue( $displayTitle ) );

		$engine = new CirrusSearch();
		$page = $this->pageWithMockParserOutput( $title, $parserOutput );
		$conn = $this->mock( Connection::class );
		$forceParse = false;
		$skipParse = false;
		$skipLinks = true; // otherwise it will query elasticsearch
		$doc = Updater::buildDocument( $engine, $page, $conn, $forceParse, $skipParse, $skipLinks );

		$this->assertTrue( $doc->has( 'display_title' ), 'field must exist when page is parsed' );
		$this->assertSame( $expected, $doc->get( 'display_title' ) );

		$skipParse = true;
		$doc = Updater::buildDocument( $engine, $page, $conn, $forceParse, $skipParse, $skipLinks );
		$this->assertFalse( $doc->has( 'display_title' ), 'field must not be set when parsing is skipped' );
	}

	public function testCreateTimestamp() {
		$pageName = 'testCreateTimestamp' . mt_rand();
		$page = new WikiPage( Title::newFromText( $pageName ) );
		$engine = new CirrusSearch();
		$conn = $this->mock( Connection::class );
		$forceParse = false;
		$skipParse = true; // parsing is unnecessary
		$skipLinks = true; // otherwise it will query elasticsearch

		// Control time to ensure the revision timestamps differ
		$currentTime = 12345;
		\MWTimestamp::setFakeTime( function () use ( &$currentTime ) {
			return $currentTime;
		} );
		try {
			// first revision should match create timestamp with revision
			$status = $this->editPage( $pageName, 'phpunit' );
			$this->assertTrue( $status->isOk() );
			$created = wfTimestamp( TS_ISO_8601, $status->getValue()['revision']->getTimestamp() );
			// Double check we are actually controlling the clock
			$this->assertEquals( wfTimestamp( TS_ISO_8601, $currentTime ), $created );
			$doc = Updater::buildDocument( $engine, $page, $conn, $forceParse, $skipParse, $skipLinks );
			$this->assertEquals( $created, $doc->get( 'create_timestamp' ) );

			// With a second revision the create timestamp should still be the old one.
			$currentTime += 42;
			$status = $this->editPage( $pageName, 'phpunit and maybe other things' );
			$this->assertTrue( $status->isOk() );
			$doc = Updater::buildDocument( $engine, $page, $conn, $forceParse, $skipParse, $skipLinks );
			$this->assertEquals( $created, $doc->get( 'create_timestamp' ) );
		} finally {
			\MWTimestamp::setFakeTime( null );
		}
	}

	private function mock( $className ) {
		return $this->getMockBuilder( $className )
			->disableOriginalConstructor()
			->getMock();
	}

	private function pageWithMockParserOutput( Title $title, ParserOutput $parserOutput ) {
		$contentHandler = $this->mock( ContentHandler::class );
		$contentHandler->expects( $this->any() )
			->method( 'getParserOutputForIndexing' )
			->will( $this->returnValue( $parserOutput ) );
		$contentHandler->expects( $this->any() )
			->method( 'getDataForSearchIndex' )
			->will( $this->returnValue( [] ) );

		$page = $this->mock( WikiPage::class );
		$page->expects( $this->any() )
			->method( 'getTitle' )
			->will( $this->returnValue( $title ) );
		$page->expects( $this->any() )
			->method( 'getOldestRevision' )
			->will( $this->returnValue( $this->mock( Revision::class ) ) );
		$page->expects( $this->any() )
			->method( 'getContentHandler' )
			->will( $this->returnValue( $contentHandler ) );
		$page->expects( $this->any() )
			->method( 'getContent' )
			->will( $this->returnValue( new \WikitextContent( 'TEST_CONTENT' ) ) );

		return $page;
	}

	private function forceTitleLang( \Title $title, $langCode ) {
		global $wgLanguageCode;
		$refl = new \ReflectionProperty( \Title::class, 'mPageLanguage' );
		$refl->setAccessible( true );
		$refl->setValue( $title, [ $langCode, $wgLanguageCode ] );
	}

	public function testFixAndFlagInvalidUTF8InSource() {
		$this->assertNotContains( 'CirrusSearchInvalidUTF8',
			Updater::fixAndFlagInvalidUTF8InSource( [ 'source_text' => 'valid' ], 1 )['template'] ?? [], 1 );
		$this->assertContains( 'Template:CirrusSearchInvalidUTF8',
			Updater::fixAndFlagInvalidUTF8InSource( [ 'source_text' => chr( 130 ) ], 1 )['template'] ?? [] );
	}
}
