<?php

namespace CirrusSearch\Fallbacks;

use CirrusSearch\CirrusIntegrationTestCase;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Search\CirrusSearchResultSet;
use CirrusSearch\Search\SearchQueryBuilder;
use CirrusSearch\Test\DummySearchResultSet;

/**
 * @covers \CirrusSearch\Fallbacks\PhraseSuggestFallbackMethod
 * @covers \CirrusSearch\Fallbacks\FallbackMethodTrait
 */
class PhraseSuggestFallbackMethodTest extends BaseFallbackMethodTest {

	public function provideTest() {
		$tests = [];
		foreach ( CirrusIntegrationTestCase::findFixtures( 'phraseSuggestResponses/*.config' ) as $testFile ) {
			$testName = substr( basename( $testFile ), 0, -7 );
			$fixture = CirrusIntegrationTestCase::loadFixture( $testFile );
			$resultSet = $this->newResultSet( $fixture['response'] );
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
	public function test(
		$queryString,
		CirrusSearchResultSet $initialResults,
		$expectedApproxScore,
		$suggestion,
		$suggestionSnippet,
		$rewritten
	) {
		$config = $this->newHashSearchConfig( [ 'CirrusSearchEnablePhraseSuggest' => true ] );
		$query = SearchQueryBuilder::newFTSearchQueryBuilder( $config, $queryString, $this->namespacePrefixParser() )
			->setAllowRewrite( true )
			->build();

		$rewrittenResults = $rewritten ? DummySearchResultSet::fakeTotalHits( $this->newTitleHelper(), 1 ) : null;
		$rewrittenQuery = $rewritten ? SearchQueryBuilder::forRewrittenQuery( $query, $suggestion, $this->namespacePrefixParser() )
			->build() : null;
		$searcherFactory = $this->getSearcherFactoryMock( $rewrittenQuery, $rewrittenResults );
		$fallback = PhraseSuggestFallbackMethod::build( $query, [ 'profile' => 'default' ] );
		if ( $expectedApproxScore > 0.0 ) {
			$this->assertNotNull( $fallback->getSuggestQueries() );
		}
		$context = new FallbackRunnerContextImpl( $initialResults, $searcherFactory, $this->namespacePrefixParser() );
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
		foreach ( CirrusIntegrationTestCase::findFixtures( 'phraseSuggest/*.config' ) as $testFile ) {
			$testName = substr( basename( $testFile ), 0, -7 );
			$fixture = CirrusIntegrationTestCase::loadFixture( $testFile );
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
		$query = SearchQueryBuilder::newFTSearchQueryBuilder( $this->newHashSearchConfig( $config ), $query, $this->namespacePrefixParser() )
			->setInitialNamespaces( $namespaces )
			->setOffset( $offset )
			->setWithDYMSuggestion( $withDYMSuggestion )
			->build();
		$method = PhraseSuggestFallbackMethod::build( $query, [ 'profile' => $profile ] );
		$suggestQueries = null;
		if ( $method !== null ) {
			$suggestQueries = $method->getSuggestQueries();
		}

		$this->assertFileContains(
			CirrusIntegrationTestCase::fixturePath( $expectedFile ),
			CirrusIntegrationTestCase::encodeFixture( $suggestQueries ),
			self::canRebuildFixture()
		);
	}

	public function testBuild() {
		$query = SearchQueryBuilder::newFTSearchQueryBuilder( new HashSearchConfig( [] ), 'foo bar', $this->namespacePrefixParser() )
			->setWithDYMSuggestion( false )
			->build();
		$this->assertNull( PhraseSuggestFallbackMethod::build( $query, [ 'profile' => 'default' ] ) );

		$query = SearchQueryBuilder::newFTSearchQueryBuilder( new HashSearchConfig( [] ), 'foo bar', $this->namespacePrefixParser() )
			->setWithDYMSuggestion( true )
			->build();
		$this->assertNull( PhraseSuggestFallbackMethod::build( $query, [ 'profile' => 'default' ] ) );

		$query = SearchQueryBuilder::newFTSearchQueryBuilder( new HashSearchConfig( [ 'CirrusSearchEnablePhraseSuggest' => false ] ), 'foo bar',
				$this->namespacePrefixParser() )
			->setWithDYMSuggestion( true )
			->build();
		$this->assertNull( PhraseSuggestFallbackMethod::build( $query, [ 'profile' => 'default' ] ) );

		$query = SearchQueryBuilder::newFTSearchQueryBuilder( new HashSearchConfig( [ 'CirrusSearchEnablePhraseSuggest' => true ] ), 'foo bar',
				$this->namespacePrefixParser() )
			->setWithDYMSuggestion( true )
			->build();
		$this->assertNotNull( PhraseSuggestFallbackMethod::build( $query, [ 'profile' => 'default' ] ) );

		$query = SearchQueryBuilder::newFTSearchQueryBuilder( new HashSearchConfig( [ 'CirrusSearchEnablePhraseSuggest' => true ] ), 'foo bar',
				$this->namespacePrefixParser() )
			->setWithDYMSuggestion( false )
			->build();
		$this->assertNull( PhraseSuggestFallbackMethod::build( $query, [ 'profile' => 'default' ] ) );
	}

	/**
	 * @covers \CirrusSearch\Fallbacks\FallbackRunnerContextImpl
	 */
	public function testDisabledIfHasASuggestionOrWasRewritten() {
		$query = SearchQueryBuilder::newFTSearchQueryBuilder(
				$this->newHashSearchConfig( [ 'CirrusSearchEnablePhraseSuggest' => true ] ), "foo bar",
				$this->namespacePrefixParser() )
			->setWithDYMSuggestion( true )
			->build();
		/**
		 * @var $method PhraseSuggestFallbackMethod
		 */
		$method = PhraseSuggestFallbackMethod::build( $query, [ 'profile' => 'default' ] );
		$this->assertNotNull( $method->getSuggestQueries() );

		$rset = DummySearchResultSet::fakeTotalHits( $this->newTitleHelper(), 10 );
		$rset->setSuggestionQuery( "test", "test" );
		$factory = $this->getMock( SearcherFactory::class );
		$factory->expects( $this->never() )->method( 'makeSearcher' );
		$context = new FallbackRunnerContextImpl( $rset, $factory, $this->namespacePrefixParser() );
		$method->rewrite( $context );
		$this->assertTrue( $context->costlyCallAllowed() );

		$rset = DummySearchResultSet::fakeTotalHits( $this->newTitleHelper(), 10 );
		$factory = $this->getMock( SearcherFactory::class );
		$factory->expects( $this->never() )->method( 'makeSearcher' );
		$context = new FallbackRunnerContextImpl( $rset, $factory, $this->namespacePrefixParser() );
		$this->assertTrue( $context->costlyCallAllowed() );
		$rset->setRewrittenQuery( "test", "test" );
		$this->assertSame( $rset, $method->rewrite( $context ) );
	}
}
