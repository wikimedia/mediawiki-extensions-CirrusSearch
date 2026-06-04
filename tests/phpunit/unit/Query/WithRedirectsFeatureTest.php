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

	/** With use+build, a leading withredirects: enters redirect mode and is host-wiki-only. */
	public function testEntersRedirectMode() {
		$config = $this->newConfig( true, true );
		$feature = new WithRedirectsFeature( $config );

		$this->assertCrossSearchStrategy(
			$feature, 'withredirects: foo', CrossSearchStrategy::hostWikiOnlyStrategy() );

		$context = $this->newContext( $config );
		$feature->apply( $context, 'withredirects: foo' );
		$this->assertTrue( $context->isRedirectScope() );
		$this->assertTrue( $context->areResultsPossible() );
	}

	public static function positionProvider() {
		return [
			'leading token enters redirect mode' => [ 'withredirects: intitle:foo', true, ' intitle:foo' ],
			'leading token, spaces before allowed' => [ '  withredirects: bar', true, ' bar' ],
			'non-leading token is an ordinary term' => [ 'foo withredirects:', false, 'foo withredirects:' ],
		];
	}

	/**
	 * withredirects: is a query header: only a leading token flips the mode.
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

	/** Apply-order guard: withredirects must be registered first so later keywords observe scope. */
	public function testRegisteredFirst() {
		$registry = new FullTextKeywordRegistry(
			new HashSearchConfig( [] ), $this->createCirrusSearchHookRunner() );
		$keywords = $registry->getKeywords();
		$this->assertInstanceOf( WithRedirectsFeature::class, $keywords[0] );
	}

	/** use:false fails closed: warns, no results, no scope. */
	public function testNotEnabled() {
		$config = $this->newConfig( false, false );
		$feature = new WithRedirectsFeature( $config );

		$this->kwAssertions->assertNoResultsPossible(
			$feature, 'withredirects: foo', [ [ 'cirrussearch-feature-withredirects-not-enabled' ] ] );

		$context = $this->newContext( $config );
		$feature->apply( $context, 'withredirects: foo' );
		$this->assertFalse( $context->isRedirectScope() );
	}

	/** use:true && build:false is a misconfiguration: warns, no results, no scope. */
	public function testMisconfigured() {
		$config = $this->newConfig( true, false );
		$feature = new WithRedirectsFeature( $config );

		$this->kwAssertions->assertNoResultsPossible(
			$feature, 'withredirects: foo', [ [ 'cirrussearch-feature-withredirects-not-enabled' ] ] );

		$context = $this->newContext( $config );
		$feature->apply( $context, 'withredirects: foo' );
		$this->assertFalse( $context->isRedirectScope() );
	}

	/** Negation has no meaning for a boolean flag: warn and return no results, even when enabled. */
	public function testNegationRejected() {
		$config = $this->newConfig( true, true );
		$feature = new WithRedirectsFeature( $config );

		$this->kwAssertions->assertNoResultsPossible(
			$feature, '-withredirects: foo', [ [ 'cirrussearch-feature-not-negatable', 'withredirects' ] ] );

		$context = $this->newContext( $config );
		$feature->apply( $context, '-withredirects: foo' );
		$this->assertFalse( $context->isRedirectScope() );
	}
}
