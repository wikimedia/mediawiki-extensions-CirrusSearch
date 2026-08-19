<?php

namespace CirrusSearch\Query;

use CirrusSearch\CirrusSearchHookRunner;
use CirrusSearch\CirrusTestCase;
use CirrusSearch\CrossSearchStrategy;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Parser\FullTextKeywordRegistry;
use CirrusSearch\Search\SearchContext;

/**
 * @covers \CirrusSearch\Query\WithRedirectsFeature
 * @covers \CirrusSearch\Query\SimpleKeywordFeature
 * @group CirrusSearch
 */
class WithRedirectsFeatureTest extends CirrusTestCase {
	use SimpleKeywordFeatureTestTrait;

	private const PAGE_TYPE_REDIRECT = [ 'term' => [ 'page_type' => 'redirect' ] ];

	/** Filters of a query in default mode, where redirect documents are hidden. */
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
			'withredirects' => [ 'withredirects', [] ],
			'onlyredirects' => [ 'onlyredirects', [ self::PAGE_TYPE_REDIRECT ] ],
		];
	}

	/**
	 * With use+build both keywords enter redirect mode and are host-wiki-only. withredirects:
	 * lets both document types through, onlyredirects: keeps only the redirects.
	 * @dataProvider keywordProvider
	 */
	public function testEntersRedirectMode( string $keyword, array $expectedFilters ) {
		$config = $this->newConfig( true, true );
		$feature = new WithRedirectsFeature( $config );

		$this->assertCrossSearchStrategy(
			$feature, "$keyword: foo", CrossSearchStrategy::hostWikiOnlyStrategy() );

		$context = $this->newContext( $config );
		$feature->apply( $context, "$keyword: foo" );
		$this->assertTrue( $context->isRedirectScope() );
		$this->assertTrue( $context->areResultsPossible() );
		$this->assertSame( $expectedFilters, $this->filtersOf( $context ) );
	}

	/** Without either keyword redirect documents are excluded rather than merely unfiltered. */
	public function testDefaultModeExcludesRedirects() {
		$context = $this->newContext( $this->newConfig( true, true ) );
		$this->assertSame( self::REDIRECTS_EXCLUDED, $this->filtersOf( $context ) );
	}

	public static function positionProvider() {
		return [
			'leading token enters redirect mode' => [ 'withredirects: intitle:foo', true, ' intitle:foo' ],
			'leading token, spaces before allowed' => [ '  withredirects: bar', true, ' bar' ],
			'non-leading token is an ordinary term' => [ 'foo withredirects:', false, 'foo withredirects:' ],
			'leading onlyredirects enters redirect mode' => [ 'onlyredirects: intitle:foo', true, ' intitle:foo' ],
			'non-leading onlyredirects is an ordinary term' => [ 'foo onlyredirects:', false, 'foo onlyredirects:' ],
		];
	}

	/**
	 * Both keywords are query headers: only a leading token flips the mode.
	 * @dataProvider positionProvider
	 */
	public function testQueryHeaderPosition( string $term, bool $isHeader, string $expectedRemaining ) {
		$config = $this->newConfig( true, true );
		$feature = new WithRedirectsFeature( $config );

		$this->assertRemaining( $feature, $term, $expectedRemaining );

		$context = $this->newContext( $config );
		$feature->apply( $context, $term );
		$this->assertSame( $isHeader, $context->isRedirectScope() );
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
	 * An unusable config fails closed: warns naming the keyword typed, no results, no scope,
	 * and redirect documents stay excluded.
	 * @dataProvider unusableConfigProvider
	 */
	public function testUnusableConfig( bool $use, bool $build, string $keyword ) {
		$config = $this->newConfig( $use, $build );
		$feature = new WithRedirectsFeature( $config );

		$this->kwAssertions->assertNoResultsPossible(
			$feature, "$keyword: foo", [ [ 'cirrussearch-feature-not-available', $keyword ] ] );

		$context = $this->newContext( $config );
		$feature->apply( $context, "$keyword: foo" );
		$this->assertFalse( $context->isRedirectScope() );
		$this->assertSame( self::REDIRECTS_EXCLUDED, $this->filtersOf( $context ) );
	}

	/** Apply-order guard: withredirects must be registered first so later keywords observe scope. */
	public function testRegisteredFirst() {
		$registry = new FullTextKeywordRegistry(
			new HashSearchConfig( [] ), $this->createCirrusSearchHookRunner() );
		$keywords = $registry->getKeywords();
		$this->assertInstanceOf( WithRedirectsFeature::class, $keywords[0] );
	}

	public static function negationProvider() {
		return [ [ 'withredirects' ], [ 'onlyredirects' ] ];
	}

	/**
	 * Negation has no meaning for a boolean flag: warn and return no results, even when enabled.
	 * @dataProvider negationProvider
	 */
	public function testNegationRejected( string $keyword ) {
		$config = $this->newConfig( true, true );
		$feature = new WithRedirectsFeature( $config );

		$this->kwAssertions->assertNoResultsPossible(
			$feature, "-$keyword: foo", [ [ 'cirrussearch-feature-not-negatable', $keyword ] ] );

		$context = $this->newContext( $config );
		$feature->apply( $context, "-$keyword: foo" );
		$this->assertFalse( $context->isRedirectScope() );
		$this->assertSame( self::REDIRECTS_EXCLUDED, $this->filtersOf( $context ) );
	}
}
