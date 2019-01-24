<?php

namespace CirrusSearch\Fallbacks;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Search\ResultSet;
use CirrusSearch\Search\SearchQueryBuilder;
use CirrusSearch\Test\DummyResultSet;
use Elastica\Query;
use Elastica\Response;
use Elastica\ResultSet\DefaultBuilder;

/**
 * @covers \CirrusSearch\Fallbacks\PhraseSuggestFallbackMethod
 */
class PhraseSuggestFallbackMethodTest extends BaseFallbackMethodTest {

	public function provideTest() {
		$tests = [];
		foreach ( CirrusTestCase::findFixtures( 'phraseSuggestResponses/*.config' ) as $testFile ) {
			$testName = substr( basename( $testFile ), 0, -7 );
			$fixture = CirrusTestCase::loadFixture( $testFile );
			$resp = new Response( $fixture['response'], 200 );
			$resultSet = new ResultSet(
				false, // Ignored here
				( new DefaultBuilder() )->buildResultSet( $resp, new Query() )
			);
			$tests[$testName] = [
				$fixture['query'],
				$resultSet,
				$fixture['approxScore'],
				$fixture['suggestion'],
				$fixture['suggestionSnippet'],
				$fixture['rewritten']
			];
		}

		return $tests;
	}

	/**
	 * @dataProvider provideTest
	 */
	public function test( $queryString, ResultSet $initialResults, $expectedApproxScore, $suggestion, $suggestionSnippet, $rewritten ) {
		$config = new HashSearchConfig( [ 'CirrusSearchEnablePhraseSuggest' => true ] );
		$query = SearchQueryBuilder::newFTSearchQueryBuilder( $config, $queryString )
			->setAllowRewrite( true )
			->build();

		$rewrittenResults = $rewritten ? DummyResultSet::fakeTotalHits( 1 ) : null;
		$rewrittenQuery = $rewritten ? SearchQueryBuilder::forRewrittenQuery( $query, $suggestion )->build() : null;
		$searcherFactory = $this->getSearcherFactoryMock( $rewrittenQuery, $rewrittenResults );
		$fallback = new PhraseSuggestFallbackMethod( $searcherFactory, $query );
		if ( $expectedApproxScore > 0.0 ) {
			$this->assertNotNull( $fallback->getSuggestQueries() );
		}
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

	public function provideTestSuggestQueries() {
		$tests = [];
		foreach ( CirrusTestCase::findFixtures( 'phraseSuggest/*.config' ) as $testFile ) {
			$testName = substr( basename( $testFile ), 0, -7 );
			$fixture = CirrusTestCase::loadFixture( $testFile );
			$expectedFile = dirname( $testFile ) . "/$testName.expected";
			$tests[$testName] = [
				$expectedFile,
				$fixture['query'],
				$fixture['namespaces'],
				$fixture['offset'],
				$fixture['with_dym'] ?? true,
				$fixture['config']
			];
		}
		return $tests;
	}

	/**
	 * @dataProvider provideTestSuggestQueries
	 */
	public function testSuggestQuery( $expectedFile, $query, $namespaces, $offset, $withDYMSuggestion, $config ) {
		$query = SearchQueryBuilder::newFTSearchQueryBuilder( new HashSearchConfig( $config ), $query )
			->setInitialNamespaces( $namespaces )
			->setOffset( $offset )
			->setWithDYMSuggestion( $withDYMSuggestion )
			->build();
		$method = new PhraseSuggestFallbackMethod( $this->getSearcherFactoryMock(), $query );
		$createIfMissing = getenv( 'CIRRUS_REBUILD_FIXTURES' ) === 'yes';

		$this->assertFileContains(
			CirrusTestCase::fixturePath( $expectedFile ),
			CirrusTestCase::encodeFixture( $method->getSuggestQueries() ),
			$createIfMissing
		);
	}
}
