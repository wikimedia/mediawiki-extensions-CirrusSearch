<?php

namespace CirrusSearch;

use CirrusSearch\Search\RedirectMode;
use CirrusSearch\Search\SearchContext;

/**
 * @covers \CirrusSearch\Search\SearchContext
 */
class SearchContextTest extends CirrusTestCase {

	/**
	 * @var SearchContext
	 */
	private $context;

	protected function setUp(): void {
		parent::setUp();
		$this->context = new SearchContext(
			$this->newHashSearchConfig(), null, null, null, null,
			$this->createNoOpMock( CirrusSearchHookRunner::class )
		);
	}

	public function testNoSyntax() {
		// No syntax is classified as full_text
		$this->context->addSyntaxUsed( 'full_text' );
		$this->assertTrue( $this->context->isSyntaxUsed() );
		$this->assertFalse( $this->context->isSpecialKeywordUsed() );
		$this->assertFalse( $this->context->isSyntaxUsed( 'accio' ) );
		$this->assertEquals( 'full_text', $this->context->getSearchType() );
	}

	public function testCheapSyntax() {
		$this->context->addSyntaxUsed( 'accio' );
		$this->context->addSyntaxUsed( 'full_text' );
		$this->assertTrue( $this->context->isSyntaxUsed() );
		$this->assertTrue( $this->context->isSyntaxUsed( 'accio' ) );
		$this->assertFalse( $this->context->isSyntaxUsed( 'prefix' ) );
		$this->assertEquals( 'full_text', $this->context->getSearchType() );
	}

	public function testNoncheapSyntax() {
		$this->context->addSyntaxUsed( 'full_text' );
		$this->context->addSyntaxUsed( 'more_like' );
		$this->assertTrue( $this->context->isSyntaxUsed( 'more_like' ) );
		$this->assertEquals( 'more_like', $this->context->getSearchType() );
	}

	public function testNoncheapSyntaxCustom() {
		$this->context->addSyntaxUsed( 'more_like' );
		$this->context->addSyntaxUsed( 'even_more_like', 101 );
		$this->assertTrue( $this->context->isSyntaxUsed( 'even_more_like' ) );
		$this->assertEquals( 'even_more_like', $this->context->getSearchType() );
	}

	public function testSyntaxOrder() {
		$syntaxes = [ 'full_text', 'more_like', 'regex' ];
		foreach ( $syntaxes as $syntax ) {
			$this->context->addSyntaxUsed( $syntax );
			$this->assertEquals( $syntax, $this->context->getSearchType() );
		}
	}

	public function testDefaultModeExcludesRedirectDocuments() {
		// The standard mode hides redirect documents.
		$this->assertSame( RedirectMode::Standard, $this->context->getRedirectMode() );
		$this->assertExcludesRedirectDocuments( $this->context->getQuery() );
	}

	public static function redirectModeProvider() {
		return [
			// mode, whether redirect documents are searchable
			'standard' => [ RedirectMode::Standard, false ],
			'noredirects' => [ RedirectMode::NoRedirects, false ],
			'withredirects' => [ RedirectMode::WithRedirects, true ],
			'onlyredirects' => [ RedirectMode::OnlyRedirects, true ],
		];
	}

	/**
	 * Only the modes that make redirect documents searchable drop the exclusion filter.
	 * noredirects: keeps them hidden, exactly as the standard mode does.
	 * @dataProvider redirectModeProvider
	 */
	public function testRedirectExclusionFollowsMode( RedirectMode $mode, bool $searchable ) {
		$this->context->setRedirectMode( $mode );
		if ( $searchable ) {
			$this->assertDoesNotExcludeRedirectDocuments( $this->context->getQuery() );
		} else {
			$this->assertExcludesRedirectDocuments( $this->context->getQuery() );
		}
	}

}
