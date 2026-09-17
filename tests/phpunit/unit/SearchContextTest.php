<?php

namespace CirrusSearch;

use CirrusSearch\Profile\ContextualProfileOverride;
use CirrusSearch\Profile\SearchProfileService;
use CirrusSearch\Search\RedirectMode;
use CirrusSearch\Search\SearchContext;
use MediaWiki\Config\HashConfig;

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

	/**
	 * A config whose dispatch service always routes searchText to $profileContext,
	 * so a test can tell a dispatched context apart from the default route.
	 */
	private function newConfigRoutingTo( string $profileContext ): SearchConfig {
		$hookRunner = $this->createCirrusSearchHookRunner( [
			'CirrusSearchProfileService' => static function ( SearchProfileService $service ) use ( $profileContext ) {
				$service->registerFTSearchQueryRoute( $profileContext, 0.5, [] );
			}
		] );
		// The CirrusSearchProfileService hook only runs for the local wiki, and a
		// HashSearchConfig only counts as local when it inherits.
		return $this->newHashSearchConfig( [], [ HashSearchConfig::FLAG_INHERIT ],
			new HashConfig( [] ), $this->hostWikiSearchProfileServiceFactory( $hookRunner ) );
	}

	public function testTheDefaultRouteSkipsDispatchAndDropsContextParams() {
		$config = $this->newConfigRoutingTo( SearchProfileService::CONTEXT_PREFIXSEARCH );
		$query = $this->getNewFTSearchQueryBuilder( $config, 'foo' )
			->addProfileContextParameter( ContextualProfileOverride::LANGUAGE, 'fr' )
			->build();

		$context = SearchContext::fromSearchQuery( $query, null,
			$this->createCirrusSearchHookRunner(), true );

		// The route would have said prefixsearch, the default route wins.
		$this->assertSame( SearchProfileService::CONTEXT_DEFAULT, $context->getProfileContext() );
		// The local language must not select a profile on the wiki being searched.
		$this->assertSame( [], $context->getProfileContextParams() );
	}

	public function testDispatchServiceDecidesWhenTheDefaultRouteIsNotAsked() {
		$config = $this->newConfigRoutingTo( SearchProfileService::CONTEXT_PREFIXSEARCH );
		$query = $this->getNewFTSearchQueryBuilder( $config, 'foo' )
			->addProfileContextParameter( ContextualProfileOverride::LANGUAGE, 'fr' )
			->build();

		$context = SearchContext::fromSearchQuery( $query, null,
			$this->createCirrusSearchHookRunner() );

		$this->assertSame( SearchProfileService::CONTEXT_PREFIXSEARCH, $context->getProfileContext() );
		$this->assertSame( [ ContextualProfileOverride::LANGUAGE => 'fr' ],
			$context->getProfileContextParams() );
	}

}
