<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\CirrusTestCase;
use MediaWiki\Content\Content;
use MediaWiki\Page\WikiPage;
use MediaWiki\Title\Title;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \CirrusSearch\Maintenance\IndexablePageResolver
 */
class IndexablePageResolverTest extends CirrusTestCase {

	private function page(): WikiPage {
		return $this->createMock( WikiPage::class );
	}

	/**
	 * @param Content|null|callable $content Content the page yields, null for "no content", or a
	 *  callable thrown from getContent() to simulate a deserialization failure.
	 */
	private function pageWithContent( $content ): WikiPage {
		$page = $this->createMock( WikiPage::class );
		$page->method( 'getTitle' )->willReturn( $this->createMock( Title::class ) );
		if ( is_callable( $content ) ) {
			$page->method( 'getContent' )->willReturnCallback( $content );
		} else {
			$page->method( 'getContent' )->willReturn( $content );
		}
		return $page;
	}

	private function content( bool $isRedirect ): Content {
		$content = $this->createMock( Content::class );
		$content->method( 'isRedirect' )->willReturn( $isRedirect );
		return $content;
	}

	/**
	 * @param callable $traceRedirect Title -> ?WikiPage
	 * @param callable $isTitleIndexable Title -> bool
	 */
	private function resolver(
		callable $traceRedirect,
		callable $isTitleIndexable,
		bool $dateBased,
		bool $buildRedirects,
		?Printer $printer = null
	): IndexablePageResolver {
		return new IndexablePageResolver(
			$traceRedirect,
			$isTitleIndexable,
			$dateBased,
			$buildRedirects,
			$printer ?? $this->createMock( Printer::class ),
			new NullLogger()
		);
	}

	/** A tracer/validator that must never be consulted on this path. */
	private function neverCalled(): callable {
		return function () {
			$this->fail( 'collaborator should not be called on this path' );
		};
	}

	// --- resolve(): content loading failure modes -----------------------------------------

	public function testDeserializationFailureSkipsPage() {
		$page = $this->pageWithContent( static function () {
			throw new RuntimeException( 'bad content' );
		} );
		$resolver = $this->resolver( $this->neverCalled(), $this->neverCalled(), true, true );
		$this->assertSame( [], $resolver->resolve( $page ) );
	}

	public function testMissingContentSkipsPageWithNotice() {
		$page = $this->pageWithContent( null );
		$page->getTitle()->method( 'getArticleID' )->willReturn( 42 );
		$printer = $this->createMock( Printer::class );
		$printer->expects( $this->once() )->method( 'output' )
			->with( "Skipping page with no content: 42\n" );
		$resolver = $this->resolver( $this->neverCalled(), $this->neverCalled(), true, true, $printer );
		$this->assertSame( [], $resolver->resolve( $page ) );
	}

	// --- resolve(): the trace gate --------------------------------------------------------

	public function testNonRedirectNeverTraces() {
		$page = $this->pageWithContent( $this->content( false ) );
		$resolver = $this->resolver( $this->neverCalled(), $this->neverCalled(), true, true );
		$this->assertSame( [ $page ], $resolver->resolve( $page ) );
	}

	public function testIdBasedRedirectNeverTraces() {
		// Full reindex visits the target as its own row, so no trace is needed here.
		$page = $this->pageWithContent( $this->content( true ) );
		$resolver = $this->resolver( $this->neverCalled(), $this->neverCalled(), false, true );
		$this->assertSame( [ $page ], $resolver->resolve( $page ) );
	}

	public function testIdBasedRedirectWithoutBuildEmitsNothing() {
		// Full reindex with no redirect documents: the redirect contributes nothing of its own.
		$page = $this->pageWithContent( $this->content( true ) );
		$resolver = $this->resolver( $this->neverCalled(), $this->neverCalled(), false, false );
		$this->assertSame( [], $resolver->resolve( $page ) );
	}

	// --- resolve(): interpreting the trace result -----------------------------------------

	public function testDateBasedRedirectTracesAndEmitsBoth() {
		$page = $this->pageWithContent( $this->content( true ) );
		$target = $this->page();
		$target->method( 'getTitle' )->willReturn( $this->createMock( Title::class ) );
		$resolver = $this->resolver(
			static fn () => $target,
			static fn () => true,
			true, true
		);
		$result = $resolver->resolve( $page );
		$this->assertSame( [ $page, $target ], $result );
	}

	public function testDateBasedRedirectWithoutBuildEmitsTargetOnly() {
		$page = $this->pageWithContent( $this->content( true ) );
		$target = $this->page();
		$target->method( 'getTitle' )->willReturn( $this->createMock( Title::class ) );
		$resolver = $this->resolver(
			static fn () => $target,
			static fn () => true,
			true, false
		);
		$this->assertSame( [ $target ], $resolver->resolve( $page ) );
	}

	public function testUnresolvableRedirectEmitsRedirectUnderBuild() {
		// Self redirect / loop / special page: traceRedirects returns null, but under build:true
		// the redirect still contributes its own document. The validator is never reached.
		$page = $this->pageWithContent( $this->content( true ) );
		$resolver = $this->resolver(
			static fn () => null,
			$this->neverCalled(),
			true, true
		);
		$this->assertSame( [ $page ], $resolver->resolve( $page ) );
	}

	public function testUnresolvableRedirectEmitsNothingWithoutBuild() {
		$page = $this->pageWithContent( $this->content( true ) );
		$resolver = $this->resolver(
			static fn () => null,
			$this->neverCalled(),
			true, false
		);
		$this->assertSame( [], $resolver->resolve( $page ) );
	}

	public function testInvalidTargetTitleIsDroppedThenDecidedAsUnresolvable() {
		// A traced target whose title can't be rebuilt from ns + text is dropped, leaving the
		// redirect to behave exactly like the unresolvable case above — under both build modes.
		$page = $this->pageWithContent( $this->content( true ) );
		$target = $this->page();
		$targetTitle = $this->createMock( Title::class );
		$targetTitle->method( 'getPrefixedText' )->willReturn( 'Bad:Title' );
		$target->method( 'getTitle' )->willReturn( $targetTitle );
		$printer = $this->createMock( Printer::class );
		$printer->expects( $this->exactly( 2 ) )->method( 'output' )
			->with( 'Skipping page with invalid title: Bad:Title' );
		$tracer = static fn () => $target;
		$invalid = static fn () => false;
		$this->assertSame(
			[ $page ],
			$this->resolver( $tracer, $invalid, true, true, $printer )->resolve( $page )
		);
		$this->assertSame(
			[],
			$this->resolver( $tracer, $invalid, true, false, $printer )->resolve( $page )
		);
	}
}
