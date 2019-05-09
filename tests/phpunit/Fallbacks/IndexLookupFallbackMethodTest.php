<?php

namespace CirrusSearch\Fallbacks;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Search\SearchQueryBuilder;
use CirrusSearch\Test\DummyResultSet;
use Elastica\Client;
use Elastica\Query;
use Elastica\Response;
use Elastica\ResultSet\DefaultBuilder;

/**
 * @covers \CirrusSearch\Fallbacks\IndexLookupFallbackMethod
 * @covers \CirrusSearch\Fallbacks\FallbackMethodTrait
 */
class IndexLookupFallbackMethodTest extends BaseFallbackMethodTest {

	public function provideTest() {
		$tests = [];
		foreach ( CirrusTestCase::findFixtures( 'indexLookupFallbackMethodResponses/*.config' ) as $testFile ) {
			$testName = substr( basename( $testFile ), 0, -7 );
			$fixture = CirrusTestCase::loadFixture( $testFile );
			$resp = new Response( $fixture['response'], 200 );
			$resultSet = ( new DefaultBuilder() )->buildResultSet( $resp, new Query() );
			$tests[$testName] = [
				$fixture['query'],
				$resultSet,
				$fixture['approxScore'],
				$fixture['suggestion'],
				$fixture['suggestionSnippet'],
			];
		}

		return $tests;
	}

	/**
	 * @dataProvider provideTest
	 */
	public function test( $queryString, \Elastica\ResultSet $response, $expectedApproxScore, $suggestion, $suggestionSnippet ) {
		$config = new HashSearchConfig( [] );
		$query = SearchQueryBuilder::newFTSearchQueryBuilder( $config, $queryString )
			->setAllowRewrite( true )
			->build();

		$rewrittenResults = DummyResultSet::fakeTotalHits( 1 );
		$rewrittenQuery = $suggestion != null ? SearchQueryBuilder::forRewrittenQuery( $query, $suggestion )->build() : null;
		$searcherFactory = $this->getSearcherFactoryMock( $rewrittenQuery, $rewrittenResults );
		$config = [ 'index' => 'lookup_index', 'query_field' => 'lookup_query_field', 'suggestion_field' => 'lookup_suggestion_field' ];
		/**
		 * @var IndexLookupFallbackMethod $fallback
		 */
		$fallback = IndexLookupFallbackMethod::build( $query, $config );
		$this->assertNotNull( $fallback->getSearchRequest( $this->getMockBuilder( Client::class )->disableOriginalConstructor()->getMock() ) );
		$initialResults = DummyResultSet::fakeTotalHits( 0 );
		$context = new FallbackRunnerContextImpl( $initialResults, $searcherFactory );
		$context->setSuggestResponse( $response );
		$this->assertEquals( $expectedApproxScore, $fallback->successApproximation( $context ) );
		if ( $expectedApproxScore > 0 ) {
			$actualNewResults = $fallback->rewrite( $context );
			$this->assertEquals( $suggestion, $rewrittenResults->getQueryAfterRewrite() );
			$this->assertEquals( $suggestionSnippet,
				$rewrittenResults->getQueryAfterRewriteSnippet() );
			$this->assertSame( $rewrittenResults, $actualNewResults );
		}
	}

	public function provideTestLookupQueries() {
		$tests = [];
		foreach ( CirrusTestCase::findFixtures( 'indexLookupFallbackMethod/*.config' ) as $testFile ) {
			$testName = substr( basename( $testFile ), 0, -7 );
			$fixture = CirrusTestCase::loadFixture( $testFile );
			$expectedFile = dirname( $testFile ) . "/$testName.expected";
			$tests[$testName] = [
				$expectedFile,
				$fixture['query'],
				$fixture['namespaces'],
				$fixture['offset'],
				$fixture['with_dym'] ?? true,
				$fixture['profile'],
			];
		}
		return $tests;
	}

	/**
	 * @dataProvider provideTestLookupQueries
	 */
	public function testSuggestQuery( $expectedFile, $query, $namespaces, $offset, $withDYMSuggestion, $profile ) {
		$query = SearchQueryBuilder::newFTSearchQueryBuilder( new HashSearchConfig( [] ), $query )
			->setInitialNamespaces( $namespaces )
			->setOffset( $offset )
			->setWithDYMSuggestion( $withDYMSuggestion )
			->build();
		/**
		 * @var IndexLookupFallbackMethod $method
		 */
		$method = IndexLookupFallbackMethod::build( $query, $profile );
		$searchQuery = null;
		if ( $method !== null ) {
			$query = $method->getSearchRequest(
				$this->getMockBuilder( Client::class )
					->disableOriginalConstructor()
					->getMock()
			);
			if ( $query !== null ) {
				$searchQuery = [
					'path' => $query->getPath(),
					'options' => $query->getOptions(),
					'query' => $query->getQuery()->toArray(),
				];
			}
		}
		$createIfMissing = getenv( 'CIRRUS_REBUILD_FIXTURES' ) === 'yes';

		$this->assertFileContains(
			CirrusTestCase::fixturePath( $expectedFile ),
			CirrusTestCase::encodeFixture( $searchQuery ),
			$createIfMissing
		);
	}

	public function testBuild() {
		$config = [ 'index' => 'lookup_index', 'query_field' => 'lookup_query_field', 'suggestion_field' => 'lookup_suggestion_field' ];

		$query = SearchQueryBuilder::newFTSearchQueryBuilder( new HashSearchConfig( [] ), 'foo bar' )
			->setWithDYMSuggestion( false )
			->build();
		$this->assertNull( IndexLookupFallbackMethod::build( $query, $config ) );

		$query = SearchQueryBuilder::newFTSearchQueryBuilder( new HashSearchConfig( [] ), 'foo bar' )
			->setWithDYMSuggestion( true )
			->build();
		$this->assertNotNull( IndexLookupFallbackMethod::build( $query, $config ) );

		$query = SearchQueryBuilder::newFTSearchQueryBuilder( new HashSearchConfig( [] ), 'foo bar' )
			->setWithDYMSuggestion( true )
			->setOffset( 10 )
			->build();
		$this->assertNull( IndexLookupFallbackMethod::build( $query, $config ) );
	}
}
