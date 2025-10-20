<?php

namespace CirrusSearch\Query;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Search\SearchContext;
use CirrusSearch\SecondTry\SecondTryRunner;

/**
 * @covers \CirrusSearch\Query\PrefixSearchQueryBuilder
 */
class PrefixSearchQueryBuilderTest extends CirrusTestCase {
	/** @var array */
	private static $WEIGHTS = [
		'title' => 2,
		'redirect' => 0.2,
		'title_asciifolding' => 1,
		'redirect_asciifolding' => 0.1
	];

	private ?SecondTryRunner $secondTryRunner;

	public function setUp(): void {
		parent::setUp();
		$this->secondTryRunner = new SecondTryRunner( [], [] );
	}

	public function testBuildsQuery() {
		$qb = new PrefixSearchQueryBuilder( $this->secondTryRunner );
		$config = new HashSearchConfig( [
			'CirrusSearchPrefixSearchStartsWithAnyWord' => false,
			'CirrusSearchPrefixWeights' => self::$WEIGHTS,
		] );
		$context = $this->getSearchContext( $config );
		// Not sure what we could reliably assert here. The code ran at least?
		$this->assertFalse( $context->isDirty() );
		$qb->build( $context, 'full keyword prefix' );
		$this->assertTrue( $context->isDirty() );
	}

	public function testRejectsOversizeQueries() {
		$qb = new PrefixSearchQueryBuilder( $this->secondTryRunner );
		$config = $this->newHashSearchConfig( [
			'CirrusSearchPrefixSearchStartsWithAnyWord' => false,
			'CirrusSearchPrefixWeights' => [],
		] );
		$context = $this->getSearchContext( $config );
		$qb->build( $context, str_repeat( 'a', 4096 ) );
		$this->assertFalse( $context->areResultsPossible() );
	}

	private function getSearchContext( \CirrusSearch\SearchConfig $config ): SearchContext {
		return new SearchContext( $config, null, null, null, null,
			$this->createCirrusSearchHookRunner() );
	}
}
