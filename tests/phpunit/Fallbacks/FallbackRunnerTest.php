<?php

namespace CirrusSearch\Fallbacks;

use CirrusSearch\CirrusConfigInterwikiResolver;
use CirrusSearch\CirrusTestCase;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\InterwikiResolver;
use CirrusSearch\Search\MSearchRequests;
use CirrusSearch\Search\MSearchResponses;
use CirrusSearch\Search\ResultSet;
use CirrusSearch\Search\SearchMetricsProvider;
use CirrusSearch\Search\SearchQuery;
use CirrusSearch\Search\SearchQueryBuilder;
use CirrusSearch\Searcher;
use CirrusSearch\Test\DummyConnection;
use CirrusSearch\Test\DummyResultSet;
use CirrusSearch\Test\MockLanguageDetector;
use Elastica\Client;
use Elastica\Query;
use Elastica\Response;
use Elastica\ResultSet\DefaultBuilder;
use Elastica\Search;
use MediaWiki\MediaWikiServices;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;

/**
 * @covers \CirrusSearch\Fallbacks\FallbackRunner
 * @covers \CirrusSearch\Fallbacks\LangDetectFallbackMethod
 * @covers \CirrusSearch\Fallbacks\PhraseSuggestFallbackMethod
 */
class FallbackRunnerTest extends CirrusTestCase {
	private $execOrder = [];

	public function testOrdering() {
		$results = DummyResultSet::fakeTotalHits( 0 );
		$methods = [];

		$methods[] = $this->getFallbackMethod( 0.1, $this->trackingCb( 'E' ), [ 'E' => 'E' ] );
		$methods[] = $this->getFallbackMethod( 0.5, $this->trackingCb( 'B' ), [ 'B' => 'B' ] );
		$methods[] = $this->getFallbackMethod( 0.0 );
		$methods[] = $this->getFallbackMethod( 0.4, $this->trackingCb( 'D' ), [ 'D' => 'D' ] );
		$methods[] = $this->getFallbackMethod( 0.5, $this->trackingCb( 'C' ), [ 'C' => 'C' ] );
		$methods[] = $this->getFallbackMethod( 0.6, $this->trackingCb( 'A' ), [ 'A' => 'A' ] );
		$runner = new FallbackRunner( $methods );
		$runner->run( $this->getMock( SearcherFactory::class ), $results, new MSearchResponses( [], [] ) );
		$this->assertEquals( [ 'A', 'B', 'C', 'D', 'E' ], $this->execOrder );
		$this->assertEquals( [
			'A' => 'A',
			'B' => 'B',
			'C' => 'C',
			'D' => 'D',
			'E' => 'E',
		], $runner->getMetrics() );
	}

	public function testEarlyStop() {
		$results = DummyResultSet::fakeTotalHits( 0 );
		$methods = [];

		$methods[] = self::getFallbackMethod( 0.1 );
		$methods[] = self::getFallbackMethod( 0.5 );
		$methods[] = self::getFallbackMethod( 1, $this->trackingCb( 'A' ) );
		$methods[] = self::getFallbackMethod( -0.4 );
		$methods[] = self::getFallbackMethod( -0.5 );
		$methods[] = self::getFallbackMethod( -0.6 );
		$runner = new FallbackRunner( $methods );
		$runner->run( $this->getMock( SearcherFactory::class ), $results, new MSearchResponses( [], [] ) );
		$this->assertEquals( [ 'A' ], $this->execOrder );
	}

	public function trackingCb( $name ): callable {
		return function ( FallbackRunnerContext $context ) use ( $name ) {
			$this->execOrder[] = $name;
			return $context->getPreviousResultSet();
		};
	}

	public static function getFallbackMethod( $prio, callable $rewritteCallback = null, array $metrics = [] ) {
		// Using a mock seems to trigger a bug when using multiple interfaces and a static method:
		// Fatal error: Cannot make static method CirrusSearch\Fallbacks\FallbackMethod::build() non-static
		// in class Mock_SearchMetricsProvider_fb4508fb in [..]Framework/MockObject/Generator.php(290)
		return new class( $prio, $rewritteCallback, $metrics ) implements FallbackMethod, SearchMetricsProvider {
			private $prio;
			private $rewritteCallback;
			private $metrics;

			/**
			 * @param int $prio
			 * @param $rewritteCallback
			 * @param $metrics
			 */
			public function __construct(
				$prio,
				callable $rewritteCallback = null,
				array $metrics = []
			) {
				$this->prio = $prio;
				$this->rewritteCallback = $rewritteCallback;
				$this->metrics = $metrics;
			}

			/**
			 * @param SearchQuery $query
			 * @param array $params
			 * @return void
			 */
			public static function build(
				SearchQuery $query,
				array $params = []
			) {
				throw new AssertionFailedError();
			}

			/**
			 * @param ResultSet $firstPassResults
			 * @return float
			 */
			public function successApproximation( FallbackRunnerContext $context ) {
				return $this->prio;
			}

			/**
			 * @param ResultSet $firstPassResults results of the initial query
			 * @param ResultSet $previousSet results returned by previous fallback method
			 * @return ResultSet
			 */
			public function rewrite( FallbackRunnerContext $context ) {
				Assert::assertNotNull( $this->rewritteCallback );
				return ( $this->rewritteCallback )( $context );
			}

			public function getMetrics() {
				return $this->metrics;
			}
		};
	}

	public function testDefaultSetup() {
		$config = new HashSearchConfig( [
			'LanguageCode' => 'en',
			'CirrusSearchEnableAltLanguage' => true,
			'CirrusSearchInterwikiThreshold' => 3,
			'CirrusSearchLanguageToWikiMap' => [
				'fr' => 'fr',
			],
			'CirrusSearchWikiToNameMap' => [
				'fr' => 'frwiki',
			],
			'CirrusSearchLanguageDetectors' => [
				'language' => MockLanguageDetector::class
			],
			'CirrusSearchMockLanguage' => 'fr',
			'CirrusSearchFetchConfigFromApi' => false,
			'CirrusSearchEnablePhraseSuggest' => true,
			'CirrusSearchFallbackProfile' => 'phrase_suggest_and_language_detection',
		] );

		MediaWikiServices::getInstance()->redefineService( InterwikiResolver::SERVICE,
			function () use ( $config ) {
				return new CirrusConfigInterwikiResolver(
					$config,
					$this->createMock( \MultiHttpClient::class ),
					\WANObjectCache::newEmpty(),
					new \EmptyBagOStuff()
				);
			}
		);
		$query = SearchQueryBuilder::newFTSearchQueryBuilder( $config, 'foobars' )
			->setAllowRewrite( true )
			->setWithDYMSuggestion( true )
			->build();
		$searcherFactory = $this->createMock( SearcherFactory::class );
		$searcherFactory->expects( $this->exactly( 2 ) )
			->method( 'makeSearcher' )
			->willReturnOnConsecutiveCalls(
				$this->mockSearcher( DummyResultSet::fakeTotalHits( 2 ) ),
				$this->mockSearcher( DummyResultSet::fakeTotalHits( 3 ) )
			);
		$runner = FallbackRunner::create( $query );
		// Phrase suggester wins and runs its fallback query
		$response = [
			"hits" => [
				"total" => 0,
				"hits" => [],
			],
			"suggest" => [
				"suggest" => [
					[
						"text" => "foubar",
						"offset" => 0,
						"options" => [
							[
								"text" => "foobar",
								"highlighted" => Searcher::HIGHLIGHT_PRE_MARKER . "foobar" . Searcher::HIGHLIGHT_POST_MARKER,
								"score" => 0.0026376657,
							]
						]
					]
				]
			]
		];
		$this->assertNotEmpty( $runner->getElasticSuggesters() );
		$initialResults = new ResultSet( false,
			( new DefaultBuilder() )->buildResultSet( new Response( $response ), new Query() ) );
		$newResults = $runner->run( $searcherFactory, $initialResults, new MSearchResponses( [], [] ) );
		$this->assertEquals( 2, $newResults->getTotalHits() );
		$iwResults = $newResults->getInterwikiResults( \SearchResultSet::INLINE_RESULTS );
		$this->assertEmpty( $iwResults );

		// LangDetect wins and runs its fallback query
		$runner = FallbackRunner::create( $query );
		$response = [
			"hits" => [
				"total" => 0,
				"hits" => [],
			],
			"suggest" => [
				"suggest" => []
			]
		];

		$this->assertNotEmpty( $runner->getElasticSuggesters() );
		$initialResults = new ResultSet( false,
			( new DefaultBuilder() )->buildResultSet( new Response( $response ), new Query() ) );
		$newResults = $runner->run( $searcherFactory, $initialResults, new MSearchResponses( [], [] ) );
		$this->assertEquals( 0, $newResults->getTotalHits() );
		$iwResults = $newResults->getInterwikiResults( \SearchResultSet::INLINE_RESULTS );
		$this->assertNotEmpty( $iwResults );
		$this->assertArrayHasKey( 'frwiki', $iwResults );
		$this->assertEquals( 3, $iwResults['frwiki']->getTotalHits() );
		$this->assertArrayEquals(
			[
				'wgCirrusSearchAltLanguageNumResults' => 3,
				'wgCirrusSearchAltLanguage' => [ 'frwiki', 'fr' ],
			],
			$runner->getMetrics()
		);
	}

	public function mockSearcher( ResultSet $resultSet ) {
		$mock = $this->createMock( Searcher::class );
		$mock->expects( $this->once() )
			->method( 'search' )
			->willReturn( \Status::newGood( $resultSet ) );
		return $mock;
	}

	public function testNoop() {
		$noop = FallbackRunner::noopRunner();
		$this->assertSame( $noop, FallbackRunner::noopRunner() );
		$this->assertEquals( $noop, new FallbackRunner( [] ) );
	}

	public function testElasticSearchRequestFallbackMethod() {
		$client = ( new DummyConnection() )->getClient();

		$initialResp = new \Elastica\ResultSet( new Response( [] ), new Query( new Query\Match( 'text', 'first' ) ), [] );
		$inital = new ResultSet( false, $initialResp );
		$query = new Query( new Query\Match( 'foo', 'bar' ) );
		$resp = new \Elastica\ResultSet( new Response( [] ), $query, [] );
		$rewritten = new ResultSet( false, $resp );
		$runner = new FallbackRunner( [ $this->mockElasticSearchRequestFallbackMethod( $query, 0.5, $resp, $rewritten ) ] );
		$requests = new MSearchRequests();
		$runner->attachSearchRequests( $requests, $client );
		$this->assertNotEmpty( $requests->getRequests() );
		$mresponses = $requests->toMSearchResponses( [ $resp ] );
		$this->assertSame( $rewritten, $runner->run( $this->getMock( SearcherFactory::class ), $inital, $mresponses ) );

		$runner = new FallbackRunner( [ $this->mockElasticSearchRequestFallbackMethod( $query, 0.0, $resp, null ) ] );
		$requests = new MSearchRequests();
		$runner->attachSearchRequests( $requests, $client );
		$this->assertEmpty( $requests->getRequests() );
		$mresponses = $requests->canceled();
		$this->assertSame( $inital, $runner->run( $this->getMock( SearcherFactory::class ), $inital, $mresponses ) );

		$runner = new FallbackRunner( [
			$this->mockElasticSearchRequestFallbackMethod( $query, 0.0, $resp, null ),
			$this->mockElasticSearchRequestFallbackMethod( $query, 0.5, $resp, $rewritten ),
		] );
		$requests = new MSearchRequests();
		$runner->attachSearchRequests( $requests, $client );
		$mresponses = $requests->toMSearchResponses( [ $resp ] );
		$this->assertFalse( $mresponses->hasResultsFor( 'fallback-1' ) );
		$this->assertTrue( $mresponses->hasResultsFor( 'fallback-2' ) );
		$this->assertSame( $rewritten, $runner->run( $this->getMock( SearcherFactory::class ), $inital, $mresponses ) );
	}

	public function mockElasticSearchRequestFallbackMethod( $query, $approx, $expectedResponse, $rewritten ) {
		return new class( $query, $approx, $expectedResponse, $rewritten ) implements FallbackMethod, ElasticSearchRequestFallbackMethod {
			private $query;
			private $approx;
			private $expectedResponse;
			private $rewritten;

			public function __construct( $query, $approx, $expectedResponse, $rewritten ) {
				$this->query = $query;
				$this->approx = $approx;
				$this->expectedResponse = $expectedResponse;
				$this->rewritten = $rewritten;
			}

			public function getSearchRequest( Client $client ) {
				if ( $this->approx <= 0 ) {
					return null;
				}
				$search = new Search( $client );
				$search->setQuery( $this->query );
				return $search;
			}

			public static function build( SearchQuery $query, array $params = [] ) {
				throw new \AssertionError();
			}

			public function successApproximation( FallbackRunnerContext $context ) {
				if ( $this->approx > 0 ) {
					Assert::assertSame( $this->expectedResponse, $context->getMethodResponse( $this ) );
				}
				return $this->approx;
			}

			public function rewrite( FallbackRunnerContext $context ) {
				Assert::assertSame( $this->expectedResponse, $context->getMethodResponse( $this ) );
				return $this->rewritten;
			}
		};
	}
}
