<?php

namespace CirrusSearch\Fallbacks;

use CirrusSearch\CirrusIntegrationTestCase;
use CirrusSearch\InterwikiResolver;
use CirrusSearch\Search\BaseCirrusSearchResultSet;
use CirrusSearch\Search\CirrusSearchResultSet;
use CirrusSearch\Search\MSearchRequests;
use CirrusSearch\Search\MSearchResponses;
use CirrusSearch\Search\SearchMetricsProvider;
use CirrusSearch\Search\SearchQuery;
use CirrusSearch\Search\SearchQueryBuilder;
use CirrusSearch\Search\TitleHelper;
use CirrusSearch\Searcher;
use CirrusSearch\Test\DummySearchResultSet;
use CirrusSearch\Test\MockLanguageDetector;
use Elastica\Client;
use Elastica\Query;
use Elastica\Response;
use Elastica\ResultSet;
use Elastica\ResultSet\DefaultBuilder;
use Elastica\Search;
use LogicException;
use MediaWiki\Status\Status;
use PHPUnit\Framework\Assert;

/**
 * @covers \CirrusSearch\Fallbacks\FallbackRunner
 * @covers \CirrusSearch\Fallbacks\LangDetectFallbackMethod
 * @covers \CirrusSearch\Fallbacks\PhraseSuggestFallbackMethod
 */
class FallbackRunnerTest extends CirrusIntegrationTestCase {
	/** @var string[] */
	private $execOrder = [];

	/**
	 * @param array $response
	 * @param bool $containedSyntax
	 * @param TitleHelper|null $titleHelper
	 * @return CirrusSearchResultSet
	 */
	protected function newResultSet(
		array $response,
		$containedSyntax = false,
		?TitleHelper $titleHelper = null
	): CirrusSearchResultSet {
		$titleHelper = $titleHelper ?: self::newTitleHelper();
		$resultSet = ( new DefaultBuilder() )->buildResultSet( new Response( $response ), new Query() );
		return new class( $resultSet, $titleHelper, $containedSyntax ) extends BaseCirrusSearchResultSet {
			/** @var ResultSet */
			private $resultSet;
			/** @var TitleHelper */
			private $titleHelper;
			/** @var bool */
			private $containedSyntax;

			public function __construct( ResultSet $resultSet, TitleHelper $titleHelper, $containedSyntax ) {
				$this->resultSet = $resultSet;
				$this->titleHelper = $titleHelper;
				$this->containedSyntax = $containedSyntax;
			}

			/**
			 * @inheritDoc
			 */
			protected function transformOneResult( \Elastica\Result $result ) {
				return new \CirrusSearch\Search\Result( null, $result );
			}

			/**
			 * @inheritDoc
			 */
			public function getElasticaResultSet() {
				return $this->resultSet;
			}

			/**
			 * @return bool
			 */
			public function searchContainedSyntax() {
				return $this->containedSyntax;
			}

			protected function getTitleHelper(): TitleHelper {
				return $this->titleHelper;
			}
		};
	}

	public function testOrdering() {
		$results = DummySearchResultSet::fakeTotalHits( self::newTitleHelper(), 0 );
		$methods = [];

		$methods['E'] = $this->getFallbackMethod( 0.1, $this->trackingCb( 'E' ), [ 'E' => 'E' ] );
		$methods['B'] = $this->getFallbackMethod( 0.5, $this->trackingCb( 'B' ), [ 'B' => 'B' ] );
		$methods['unused'] = $this->getFallbackMethod( 0.0 );
		$methods['D'] = $this->getFallbackMethod( 0.4, $this->trackingCb( 'D' ), [ 'D' => 'D' ] );
		$methods['C'] = $this->getFallbackMethod( 0.5, $this->trackingCb( 'C' ), [ 'C' => 'C' ] );
		$methods['A'] = $this->getFallbackMethod( 0.6, $this->trackingCb( 'A' ), [ 'A' => 'A' ] );
		$runner = new FallbackRunner( $methods );
		$runner->run(
			$this->createMock( SearcherFactory::class ),
			$results,
			new MSearchResponses( [], [] ),
			$this->namespacePrefixParser(),
			$this->createCirrusSearchHookRunner()
		);
		$this->assertEquals( [ 'A', 'B', 'C', 'D', 'E' ], $this->execOrder );
	}

	public function testEarlyStop() {
		$results = DummySearchResultSet::fakeTotalHits( self::newTitleHelper(), 0 );
		$methods = [];

		$methods['q'] = self::getFallbackMethod( 0.1 );
		$methods['w'] = self::getFallbackMethod( 0.5 );
		$methods['A'] = self::getFallbackMethod( 1, $this->trackingCb( 'A' ) );
		$methods['r'] = self::getFallbackMethod( -0.4 );
		$methods['t'] = self::getFallbackMethod( -0.5 );
		$methods['y'] = self::getFallbackMethod( -0.6 );
		$runner = new FallbackRunner( $methods );
		$runner->run(
			$this->createMock( SearcherFactory::class ),
			$results,
			new MSearchResponses( [], [] ),
			$this->namespacePrefixParser(),
			$this->createCirrusSearchHookRunner()
		);
		$this->assertEquals( [ 'A' ], $this->execOrder );
	}

	public static function metricsProvider() {
		return [
			'no fallbacks applied' => [
				'expectedMainResults' => [ 'name' => '__main__', 'action' => null ],
				'expectedQuerySuggestion' => null,
				'expectedResponseMetrics' => [],
				'methods' => [],
			],
			'typical query suggestion' => [
				'expectedMainResults' => [ 'name' => '__main__', 'action' => null ],
				'expectedQuerySuggestion' => [
					'name' => 'profile-name',
					'action' => FallbackStatus::ACTION_SUGGEST_QUERY
				],
				'expectedResponseMetrics' => [],
				'methods' => [
					'profile-name' => self::getFallbackMethod( 1,
						self::fallbackStatusCb( FallbackStatus::suggestQuery( 'phpunit' ) ) ),
				],
			],
			'rewritten search results' => [
				'expectedMainResults' => [
					'name' => 'fallback',
					'action' => FallbackStatus::ACTION_REPLACE_LOCAL_RESULTS
				],
				'expectedQuerySuggestion' => [
					'name' => 'fallback',
					'action' => FallbackStatus::ACTION_REPLACE_LOCAL_RESULTS
				],
				'expectedResponseMetrics' => [],
				'methods' => function ( $test ) {
					$results = DummySearchResultSet::fakeTotalHits( $test->newTitleHelper(), 0 );
					return [
						'fallback' => self::getFallbackMethod( 0.5, self::fallbackStatusCb(
							FallbackStatus::replaceLocalResults( $results, 'phpunit' ) ) ),
					];
				},
			],
			// This isn't expected to happen, later fallbacks are supposed to see a suggestion was already provided
			// and not provide a suggestion, but assert what we expect to happen anyways.
			'multiple overriding query suggestion' => [
				'expectedMainResults' => [ 'name' => '__main__', 'action' => null ],
				'expectedQuerySuggestion' => [
					'name' => 'profile-name',
					'action' => FallbackStatus::ACTION_SUGGEST_QUERY
				],
				'expectedResponseMetrics' => [],
				'methods' => [
					'override' => self::getFallbackMethod( 0.8,
						self::fallbackStatusCb( FallbackStatus::suggestQuery( 'phpunit' ) ) ),
					'profile-name' => self::getFallbackMethod( 0.5,
						self::fallbackStatusCb( FallbackStatus::suggestQuery( 'phpunit' ) ) ),
				],
			],
		];
	}

	/**
	 * @dataProvider metricsProvider
	 */
	public function testMetrics( array $expectedMainResults, ?array $expectedQuerySuggestion, array $expectedResponseMetrics, $methods ) {
		$results = DummySearchResultSet::fakeTotalHits( self::newTitleHelper(), 0 );
		if ( $methods instanceof \Closure ) {
			// The dummy result set requires $this to initialize. Support
			// defining methods as a closure that returns the methods.
			$methods = $methods( $this );
		}
		$runner = new FallbackRunner( $methods );
		$runner->run(
			$this->createMock( SearcherFactory::class ),
			$results,
			new MSearchResponses( [], [] ),
			$this->namespacePrefixParser(),
			$this->createCirrusSearchHookRunner()
		);
		$expected = [
			'wgCirrusSearchFallback' => [
				'mainResults' => $expectedMainResults,
				'querySuggestion' => $expectedQuerySuggestion,
				'responseMetrics' => $expectedResponseMetrics,
			]
		];
		$this->assertEquals( $expected, $runner->getMetrics() );
	}

	public function trackingCb( $name ): callable {
		return function ( FallbackRunnerContext $context ) use ( $name ) {
			$this->execOrder[] = $name;
			return FallbackStatus::noSuggestion();
		};
	}

	public static function fallbackStatusCb( FallbackStatus $status ): callable {
		return static function ( FallbackRunnerContext $context ) use ( $status ) {
			return $status;
		};
	}

	public static function getFallbackMethod( $prio, ?callable $rewritteCallback = null, array $metrics = [] ) {
		// Using a mock seems to trigger a bug when using multiple interfaces and a static method:
		// Fatal error: Cannot make static method CirrusSearch\Fallbacks\FallbackMethod::build() non-static
		// in class Mock_SearchMetricsProvider_fb4508fb in [..]Framework/MockObject/Generator.php(290)
		return new class( $prio, $rewritteCallback, $metrics ) implements FallbackMethod, SearchMetricsProvider {
			/** @var float */
			private $prio;
			/** @var callable|null */
			private $rewritteCallback;
			private array $metrics;

			/**
			 * @param float $prio
			 * @param callable|null $rewritteCallback
			 * @param array $metrics
			 */
			public function __construct(
				$prio,
				?callable $rewritteCallback = null,
				array $metrics = []
			) {
				$this->prio = $prio;
				$this->rewritteCallback = $rewritteCallback;
				$this->metrics = $metrics;
			}

			/**
			 * @inheritDoc
			 */
			public static function build(
				SearchQuery $query,
				array $params,
				InterwikiResolver $resolver
			) {
				throw new LogicException( __METHOD__ . ' not expected to call' );
			}

			/**
			 * @param FallbackRunnerContext $context
			 * @return float
			 */
			public function successApproximation( FallbackRunnerContext $context ) {
				return $this->prio;
			}

			public function rewrite( FallbackRunnerContext $context ): FallbackStatus {
				Assert::assertNotNull( $this->rewritteCallback );
				return ( $this->rewritteCallback )( $context );
			}

			public function getMetrics() {
				return $this->metrics;
			}
		};
	}

	public function testDefaultSetup() {
		$config = $this->newHashSearchConfig( [
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

		$query = SearchQueryBuilder::newFTSearchQueryBuilder( $config, 'foobars',
			$this->namespacePrefixParser(), $this->createCirrusSearchHookRunner() )
			->setAllowRewrite( true )
			->setWithDYMSuggestion( true )
			->build();
		$searcherFactory = $this->createMock( SearcherFactory::class );
		$searcherFactory->expects( $this->exactly( 2 ) )
			->method( 'makeSearcher' )
			->willReturnOnConsecutiveCalls(
				$this->mockSearcher( DummySearchResultSet::fakeTotalHits( self::newTitleHelper(), 2 ) ),
				$this->mockSearcher( DummySearchResultSet::fakeTotalHits( self::newTitleHelper(), 3 ) )
			);
		$runner = FallbackRunner::create( $query, $this->newManualInterwikiResolver( $config ) );
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
								"highlighted" => Searcher::HIGHLIGHT_PRE_MARKER . "foobar" .
									Searcher::HIGHLIGHT_POST_MARKER,
								"score" => 0.0026376657,
							]
						]
					]
				]
			]
		];
		$this->assertNotEmpty( $runner->getElasticSuggesters() );
		$initialResults = $this->newResultSet( $response );
		$newResults = $runner->run(
			$searcherFactory,
			$initialResults,
			new MSearchResponses( [], [] ),
			$this->namespacePrefixParser(),
			$this->createCirrusSearchHookRunner()
		);
		$this->assertEquals( 2, $newResults->getTotalHits() );
		$iwResults = $newResults->getInterwikiResults( \ISearchResultSet::INLINE_RESULTS );
		$this->assertSame( [], $iwResults );

		// LangDetect wins and runs its fallback query
		$runner = FallbackRunner::create( $query, $this->newManualInterwikiResolver( $config ) );
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
		$initialResults = $this->newResultSet( $response );
		$newResults = $runner->run(
			$searcherFactory,
			$initialResults,
			new MSearchResponses( [], [] ),
			$this->namespacePrefixParser(),
			$this->createCirrusSearchHookRunner()
		);
		$this->assertSame( 0, $newResults->getTotalHits() );
		$iwResults = $newResults->getInterwikiResults( \ISearchResultSet::INLINE_RESULTS );
		$this->assertNotEmpty( $iwResults );
		$this->assertArrayHasKey( 'frwiki', $iwResults );
		$this->assertEquals( 3, $iwResults['frwiki']->getTotalHits() );
	}

	public function mockSearcher( CirrusSearchResultSet $resultSet ) {
		$mock = $this->createMock( Searcher::class );
		$mock->expects( $this->once() )
			->method( 'search' )
			->willReturn( Status::newGood( $resultSet ) );
		return $mock;
	}

	public function testNoop() {
		$noop = FallbackRunner::noopRunner();
		$this->assertSame( $noop, FallbackRunner::noopRunner() );
		$this->assertEquals( $noop, new FallbackRunner( [] ) );
	}

	public function testElasticSearchRequestFallbackMethod() {
		$client = $this->newEngine()->getConnection()->getClient();

		$inital = $this->newResultSet( [] );
		$query = new Query( new Query\MatchQuery( 'foo', 'bar' ) );
		$hitResp = new \Elastica\ResultSet( new Response( [] ), $query, [ new \Elastica\Result( [] ) ] );
		$noHitResp = new \Elastica\ResultSet( new Response( [] ), $query, [] );
		$rewritten = $this->newResultSet( [] );
		$runner = new FallbackRunner( [ 'a' => $this->mockElasticSearchRequestFallbackMethod( $query, 0.5, $noHitResp, $rewritten ) ] );
		$requests = new MSearchRequests();
		$runner->attachSearchRequests( $requests, $client );
		$this->assertNotEmpty( $requests->getRequests() );
		$mresponses = $requests->toMSearchResponses( [ $noHitResp ] );
		$this->assertSame( $rewritten, $runner->run( $this->createMock( SearcherFactory::class ), $inital, $mresponses,
			$this->namespacePrefixParser(), $this->createCirrusSearchHookRunner() ) );
		$this->assertEquals(
			[ 'a' => 0 ],
			$runner->getMetrics()['wgCirrusSearchFallback']['responseMetrics']
		);

		$runner = new FallbackRunner( [ 'a' => $this->mockElasticSearchRequestFallbackMethod( $query, 0.0, $noHitResp, null ) ] );
		$requests = new MSearchRequests();
		$runner->attachSearchRequests( $requests, $client );
		$this->assertSame( [], $requests->getRequests() );
		$mresponses = $requests->failure( Status::newFatal( 'error' ) );
		$this->assertSame( $inital, $runner->run( $this->createMock( SearcherFactory::class ), $inital, $mresponses,
			$this->namespacePrefixParser(), $this->createCirrusSearchHookRunner() ) );
		// failed request doesn't respond with 0, it's unknown
		$this->assertEquals( [], $runner->getMetrics()['wgCirrusSearchFallback']['responseMetrics'] );

		$runner = new FallbackRunner( [
			'a' => $this->mockElasticSearchRequestFallbackMethod( $query, 0.0, $noHitResp, null ),
			'b' => $this->mockElasticSearchRequestFallbackMethod( $query, 0.5, $hitResp, $rewritten ),
		] );
		$requests = new MSearchRequests();
		$runner->attachSearchRequests( $requests, $client );
		$mresponses = $requests->toMSearchResponses( [ $hitResp ] );
		$this->assertFalse( $mresponses->hasResultsFor( 'fallback-1' ) );
		$this->assertTrue( $mresponses->hasResultsFor( 'fallback-2' ) );
		$this->assertSame( $rewritten, $runner->run( $this->createMock( SearcherFactory::class ), $inital, $mresponses,
			$this->namespacePrefixParser(), $this->createCirrusSearchHookRunner() ) );
		// 'a' has a zero % success probability, doesn't get queried.
		$this->assertEquals(
			[ 'b' => 1 ],
			$runner->getMetrics()['wgCirrusSearchFallback']['responseMetrics']
		);

		$runner = new FallbackRunner( [
			'a' => $this->mockElasticSearchRequestFallbackMethod( $query, 0.5, $hitResp, $rewritten ),
			'b' => $this->mockElasticSearchRequestFallbackMethod( $query, 0.5, $hitResp, $rewritten ),
		] );
		$requests = new MSearchRequests();
		$runner->attachSearchRequests( $requests, $client );
		$mresponses = $requests->toMSearchResponses( [ $hitResp, $hitResp ] );
		$this->assertTrue( $mresponses->hasResultsFor( 'fallback-1' ) );
		$this->assertTrue( $mresponses->hasResultsFor( 'fallback-2' ) );
		$this->assertSame( $rewritten, $runner->run( $this->createMock( SearcherFactory::class ), $inital, $mresponses,
			$this->namespacePrefixParser(), $this->createCirrusSearchHookRunner() ) );
		// Both run, both reported
		$this->assertEquals(
			[ 'a' => 1, 'b' => 1 ],
			$runner->getMetrics()['wgCirrusSearchFallback']['responseMetrics']
		);
	}

	public function mockElasticSearchRequestFallbackMethod( $query, $approx, $expectedResponse, $rewritten ) {
		return new class(
			$query,
			$approx,
			$expectedResponse,
			$rewritten
		) implements FallbackMethod, ElasticSearchRequestFallbackMethod {
			/** @var Query */
			private $query;
			/** @var float */
			private $approx;
			/** @var \Elastica\ResultSet */
			private $expectedResponse;
			/** @var CirrusSearchResultSet */
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

			public static function build( SearchQuery $query, array $params, InterwikiResolver $interwikiResolver ) {
				throw new \AssertionError();
			}

			public function successApproximation( FallbackRunnerContext $context ) {
				if ( $this->approx > 0 ) {
					Assert::assertTrue( $context->hasMethodResponse() );
					Assert::assertSame( $this->expectedResponse, $context->getMethodResponse( $this ) );
				} else {
					Assert::assertFalse( $context->hasMethodResponse() );
				}
				return $this->approx;
			}

			public function rewrite( FallbackRunnerContext $context ): FallbackStatus {
				Assert::assertSame( $this->expectedResponse, $context->getMethodResponse( $this ) );
				return FallbackStatus::replaceLocalResults( $this->rewritten, 'phpunit rewritten' );
			}
		};
	}
}
