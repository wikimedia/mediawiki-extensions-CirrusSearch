<?php

namespace CirrusSearch\Query;

use CirrusSearch\CirrusSearchHookRunner;
use CirrusSearch\CirrusTestCase;
use CirrusSearch\CrossSearchStrategy;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Parser\FullTextKeywordRegistry;
use CirrusSearch\Search\RedirectMode;
use CirrusSearch\Search\SearchContext;

/**
 * @covers \CirrusSearch\Query\RedirectModeFeature
 * @covers \CirrusSearch\Query\SimpleKeywordFeature
 * @group CirrusSearch
 */
class RedirectModeFeatureTest extends CirrusTestCase {
	use SimpleKeywordFeatureTestTrait;

	private const PAGE_TYPE_REDIRECT = [ 'term' => [ 'page_type' => 'redirect' ] ];

	/** Filters of a query where redirect documents are hidden. */
	private const REDIRECTS_EXCLUDED = [ [ 'bool' => [ 'must_not' => [ self::PAGE_TYPE_REDIRECT ] ] ] ];

	private function newConfig( bool $use, bool $build ): HashSearchConfig {
		return new HashSearchConfig( [
			'CirrusSearchRedirectDocuments' => [ 'use' => $use, 'build' => $build ],
		] );
	}

	private function newContext( HashSearchConfig $config ): SearchContext {
		return new SearchContext(
			$config, null, null, null, null,
			$this->createNoOpMock( CirrusSearchHookRunner::class )
		);
	}

	/**
	 * The filter clauses the context hands to elasticsearch, which is where the redirect
	 * restriction (or its absence) ends up.
	 */
	private function filtersOf( SearchContext $context ): array {
		return $context->getQuery()->toArray()['bool']['filter'] ?? [];
	}

	public static function keywordProvider() {
		return [
			'withredirects' => [ 'withredirects', RedirectMode::WithRedirects, [] ],
			'onlyredirects' => [
				'onlyredirects', RedirectMode::OnlyRedirects, [ self::PAGE_TYPE_REDIRECT ] ],
			'noredirects' => [ 'noredirects', RedirectMode::NoRedirects, self::REDIRECTS_EXCLUDED ],
		];
	}

	/**
	 * With use+build every keyword selects its mode and is host-wiki-only. withredirects:
	 * lets both document types through, onlyredirects: keeps only the redirects, and
	 * noredirects: leaves the redirect documents hidden exactly as the default does.
	 * @dataProvider keywordProvider
	 */
	public function testSelectsMode( string $keyword, RedirectMode $mode, array $expectedFilters ) {
		$config = $this->newConfig( true, true );
		$feature = new RedirectModeFeature( $config );

		$this->assertCrossSearchStrategy(
			$feature, "$keyword: foo", CrossSearchStrategy::hostWikiOnlyStrategy() );

		$context = $this->newContext( $config );
		$feature->apply( $context, "$keyword: foo" );
		$this->assertSame( $mode, $context->getRedirectMode() );
		$this->assertTrue( $context->areResultsPossible() );
		$this->assertSame( $expectedFilters, $this->filtersOf( $context ) );
	}

	/** Without a keyword redirect documents are excluded rather than merely unfiltered. */
	public function testDefaultModeExcludesRedirects() {
		$context = $this->newContext( $this->newConfig( true, true ) );
		$this->assertSame( RedirectMode::Standard, $context->getRedirectMode() );
		$this->assertSame( self::REDIRECTS_EXCLUDED, $this->filtersOf( $context ) );
	}

	public static function positionProvider() {
		return [
			'leading token selects the mode' => [
				'withredirects: intitle:foo', RedirectMode::WithRedirects, ' intitle:foo' ],
			'leading token, spaces before allowed' => [
				'  withredirects: bar', RedirectMode::WithRedirects, ' bar' ],
			'non-leading token is an ordinary term' => [
				'foo withredirects:', RedirectMode::Standard, 'foo withredirects:' ],
			'leading onlyredirects selects the mode' => [
				'onlyredirects: intitle:foo', RedirectMode::OnlyRedirects, ' intitle:foo' ],
			'non-leading onlyredirects is an ordinary term' => [
				'foo onlyredirects:', RedirectMode::Standard, 'foo onlyredirects:' ],
			'leading noredirects selects the mode' => [
				'noredirects: intitle:foo', RedirectMode::NoRedirects, ' intitle:foo' ],
			'non-leading noredirects is an ordinary term' => [
				'foo noredirects:', RedirectMode::Standard, 'foo noredirects:' ],
		];
	}

	/**
	 * Every keyword is a query header: only a leading token selects a mode.
	 * @dataProvider positionProvider
	 */
	public function testQueryHeaderPosition(
		string $term, RedirectMode $expectedMode, string $expectedRemaining
	) {
		$config = $this->newConfig( true, true );
		$feature = new RedirectModeFeature( $config );
		$context = $this->newContext( $config );

		$remaining = $feature->apply( $context, $term );

		$this->assertSame( $expectedRemaining, $remaining );
		$this->assertSame( $expectedMode, $context->getRedirectMode() );
	}

	public static function unusableConfigProvider() {
		return [
			'use:false' => [ false, false, 'withredirects' ],
			'use:false, onlyredirects' => [ false, false, 'onlyredirects' ],
			'build:false' => [ true, false, 'withredirects' ],
			'build:false, onlyredirects' => [ true, false, 'onlyredirects' ],
		];
	}

	/**
	 * An unusable config fails closed for the keywords that need redirect documents: warns
	 * naming the keyword typed, no results, no mode change, and redirect documents stay
	 * excluded.
	 * @dataProvider unusableConfigProvider
	 */
	public function testUnusableConfig( bool $use, bool $build, string $keyword ) {
		$config = $this->newConfig( $use, $build );
		$feature = new RedirectModeFeature( $config );

		$this->kwAssertions->assertNoResultsPossible(
			$feature, "$keyword: foo", [ [ 'cirrussearch-feature-not-available', $keyword ] ] );

		$context = $this->newContext( $config );
		$feature->apply( $context, "$keyword: foo" );
		$this->assertSame( RedirectMode::Standard, $context->getRedirectMode() );
		$this->assertSame( self::REDIRECTS_EXCLUDED, $this->filtersOf( $context ) );
	}

	public static function noRedirectsConfigProvider() {
		return [
			'both off' => [ false, false ],
			'build off' => [ true, false ],
			'both on' => [ true, true ],
		];
	}

	/**
	 * noredirects: is not gated on CirrusSearchRedirectDocuments. It only drops the target's
	 * redirect array from the query, and that array predates redirect documents, so the
	 * keyword works on a wiki that builds none.
	 * @dataProvider noRedirectsConfigProvider
	 */
	public function testNoRedirectsIsUngated( bool $use, bool $build ) {
		$config = $this->newConfig( $use, $build );
		$feature = new RedirectModeFeature( $config );

		$this->kwAssertions->assertWarnings( $feature, [], 'noredirects: foo' );

		$context = $this->newContext( $config );
		$feature->apply( $context, 'noredirects: foo' );
		$this->assertSame( RedirectMode::NoRedirects, $context->getRedirectMode() );
		$this->assertTrue( $context->areResultsPossible() );
		// Unlike its two siblings, it leaves the redirect documents hidden.
		$this->assertSame( self::REDIRECTS_EXCLUDED, $this->filtersOf( $context ) );
	}

	/** Apply-order guard: the feature must be registered first so later keywords see the mode. */
	public function testRegisteredFirst() {
		$registry = new FullTextKeywordRegistry(
			new HashSearchConfig( [] ), $this->createCirrusSearchHookRunner() );
		$keywords = $registry->getKeywords();
		$this->assertInstanceOf( RedirectModeFeature::class, $keywords[0] );
	}

	public static function negationProvider() {
		return [ [ 'withredirects' ], [ 'onlyredirects' ], [ 'noredirects' ] ];
	}

	/**
	 * Negation has no meaning for a boolean flag: warn and return no results, even when enabled.
	 * @dataProvider negationProvider
	 */
	public function testNegationRejected( string $keyword ) {
		$config = $this->newConfig( true, true );
		$feature = new RedirectModeFeature( $config );

		$this->kwAssertions->assertNoResultsPossible(
			$feature, "-$keyword: foo", [ [ 'cirrussearch-feature-not-negatable', $keyword ] ] );

		$context = $this->newContext( $config );
		$feature->apply( $context, "-$keyword: foo" );
		$this->assertSame( RedirectMode::Standard, $context->getRedirectMode() );
		$this->assertSame( self::REDIRECTS_EXCLUDED, $this->filtersOf( $context ) );
	}
}
