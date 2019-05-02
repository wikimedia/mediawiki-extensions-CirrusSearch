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
		$fallback = PhraseSuggestFallbackMethod::build( $searcherFactory, $query, [ 'profile' => 'default' ] );
		if ( $expectedApproxScore > 0.0 ) {
			$this->assertNotNull( $fallback->getSuggestQueries() );
		}
		$context = new FallbackRunnerContextImpl( $initialResults );
		$this->assertEquals( $expectedApproxScore, $fallback->successApproximation( $context ) );
		if ( $expectedApproxScore > 0 ) {
			$actualNewResults = $fallback->rewrite( $context );
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
				$fixture['profile'] ?? 'default',
				$fixture['config']
			];
		}
		return $tests;
	}

	/**
	 * @dataProvider provideTestSuggestQueries
	 */
	public function testSuggestQuery( $expectedFile, $query, $namespaces, $offset, $withDYMSuggestion, $profile, $config ) {
		$query = SearchQueryBuilder::newFTSearchQueryBuilder( new HashSearchConfig( $config ), $query )
			->setInitialNamespaces( $namespaces )
			->setOffset( $offset )
			->setWithDYMSuggestion( $withDYMSuggestion )
			->build();
		$method = PhraseSuggestFallbackMethod::build( $this->getSearcherFactoryMock(), $query, [ 'profile' => $profile ] );
		$suggestQueries = null;
		if ( $method !== null ) {
			$suggestQueries = $method->getSuggestQueries();
		}
		$createIfMissing = getenv( 'CIRRUS_REBUILD_FIXTURES' ) === 'yes';

		$this->assertFileContains(
			CirrusTestCase::fixturePath( $expectedFile ),
			CirrusTestCase::encodeFixture( $suggestQueries ),
			$createIfMissing
		);
	}

	public function testBuild() {
		$factory = $this->getMock( SearcherFactory::class );
		$query = SearchQueryBuilder::newFTSearchQueryBuilder( new HashSearchConfig( [] ), 'foo bar' )
			->setWithDYMSuggestion( false )
			->build();
		$this->assertNull( PhraseSuggestFallbackMethod::build( $factory, $query, [ 'profile' => 'default' ] ) );

		$query = SearchQueryBuilder::newFTSearchQueryBuilder( new HashSearchConfig( [] ), 'foo bar' )
			->setWithDYMSuggestion( true )
			->build();
		$this->assertNull( PhraseSuggestFallbackMethod::build( $factory, $query, [ 'profile' => 'default' ] ) );

		$query = SearchQueryBuilder::newFTSearchQueryBuilder( new HashSearchConfig( [ 'CirrusSearchEnablePhraseSuggest' => false ] ), 'foo bar' )
			->setWithDYMSuggestion( true )
			->build();
		$this->assertNull( PhraseSuggestFallbackMethod::build( $factory, $query, [ 'profile' => 'default' ] ) );

		$query = SearchQueryBuilder::newFTSearchQueryBuilder( new HashSearchConfig( [ 'CirrusSearchEnablePhraseSuggest' => true ] ), 'foo bar' )
			->setWithDYMSuggestion( true )
			->build();
		$this->assertNotNull( PhraseSuggestFallbackMethod::build( $factory, $query, [ 'profile' => 'default' ] ) );

		$query = SearchQueryBuilder::newFTSearchQueryBuilder( new HashSearchConfig( [ 'CirrusSearchEnablePhraseSuggest' => true ] ), 'foo bar' )
			->setWithDYMSuggestion( false )
			->build();
		$this->assertNull( PhraseSuggestFallbackMethod::build( $factory, $query, [ 'profile' => 'default' ] ) );
	}

	public function testDisabledIfHasASuggestionOrWasRewritten() {
		$factory = $this->getSearcherFactoryMock();
		$query = SearchQueryBuilder::newFTSearchQueryBuilder( new HashSearchConfig( [ 'CirrusSearchEnablePhraseSuggest' => true ] ), "foo bar" )
			->setWithDYMSuggestion( true )
			->build();
		/**
		 * @var $method PhraseSuggestFallbackMethod
		 */
		$method = PhraseSuggestFallbackMethod::build( $factory, $query, [ 'profile' => 'default' ] );
		$this->assertNotNull( $method->getSuggestQueries() );

		$rset = DummyResultSet::fakeTotalHits( 10 );
		$rset->setSuggestionQuery( "test", "test" );
		$context = new FallbackRunnerContextImpl( $rset );
		$this->assertSame( $rset, $method->rewrite( $context ) );

		$rset = DummyResultSet::fakeTotalHits( 10 );
		$rset->setRewrittenQuery( "test", "test" );
		$context = new FallbackRunnerContextImpl( $rset );
		$this->assertSame( $rset, $method->rewrite( $context ) );
	}
}
