<?php

namespace CirrusSearch\Fallbacks;

use CirrusSearch\DummyResultSet;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Search\SearchQueryBuilder;

/**
 * @covers \CirrusSearch\Fallbacks\PhraseSuggestFallbackMethod
 */
class PhraseSuggestFallbackMethodTest extends BaseFallbackMethodTest {

	public function provideTest() {
		return [
			'fallback worked' => [
				'foubar<',
				'foobar<',
				0.5,
				0,
				3
			],
			'fallback not triggered because nothing is suggested' => [
				'foubar',
				null,
				0.0,
				0,
				0
			],
			'fallback failed because the backend failed' => [
				'foubar',
				'foobar',
				0.5,
				0,
				-1
			],
			'fallback not triggered because the results threshold is not met' => [
				'foubar',
				'foobar',
				0.0,
				1,
				0
			],
			'fallback not triggered because the query is complex' => [
				'intitle:foubar',
				'intitle:foobar',
				0.0,
				1,
				0
			],
		];
	}

	/**
	 * @dataProvider provideTest
	 */
	public function test( $queryString, $suggestionQuery, $expectedApproxScore, $numInitialResults, $numRewrittenResults ) {
		$config = new HashSearchConfig( [] );
		$query = SearchQueryBuilder::newFTSearchQueryBuilder( $config, $queryString )
			->setAllowRewrite( true )
			->build();
		$rewritten = $suggestionQuery !== null ? SearchQueryBuilder::forRewrittenQuery( $query, $suggestionQuery )->build() : null;

		$rewrittenResults = $numRewrittenResults >= 0 ? DummyResultSet::fakeNumRows( $numRewrittenResults ) : null;
		$searcherFactory = $this->getSearcherFactoryMock( $expectedApproxScore > 0 ? $rewritten : null, $rewrittenResults );
		$fallback = new PhraseSuggestFallbackMethod( $searcherFactory, $query );
		$initialResults = DummyResultSet::fakeNumRowWithSuggestion( $numInitialResults, $suggestionQuery, htmlspecialchars( $suggestionQuery ) );
		$this->assertEquals( $expectedApproxScore, $fallback->successApproximation( $initialResults ) );
		if ( $expectedApproxScore > 0 ) {
			$actualNewResults = $fallback->rewrite( $initialResults, $initialResults );
			if ( $rewrittenResults === null ) {
				$this->assertSame( $initialResults, $actualNewResults );
				$this->assertNull( $actualNewResults->getQueryAfterRewrite() );
				$this->assertNull( $actualNewResults->getQueryAfterRewriteSnippet() );
			} else {
				$this->assertEquals( $initialResults->getSuggestionQuery(), $rewrittenResults->getQueryAfterRewrite() );
				$this->assertEquals( $initialResults->getSuggestionSnippet(), $rewrittenResults->getQueryAfterRewriteSnippet() );
				$this->assertSame( $rewrittenResults, $actualNewResults );
			}
		}
	}
}
