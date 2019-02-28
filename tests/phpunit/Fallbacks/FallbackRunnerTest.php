<?php

namespace CirrusSearch\Fallbacks;

use CirrusSearch\CirrusConfigInterwikiResolver;
use CirrusSearch\CirrusTestCase;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\InterwikiResolver;
use CirrusSearch\Search\ResultSet;
use CirrusSearch\Search\SearchMetricsProvider;
use CirrusSearch\Search\SearchQuery;
use CirrusSearch\Search\SearchQueryBuilder;
use CirrusSearch\Searcher;
use CirrusSearch\Test\DummyResultSet;
use CirrusSearch\Test\MockLanguageDetector;
use Elastica\Query;
use Elastica\Response;
use Elastica\ResultSet\DefaultBuilder;
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
		$runner->run( $results );
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
		$runner->run( $results );
		$this->assertEquals( [ 'A' ], $this->execOrder );
	}

	public function trackingCb( $name ): callable {
		return function ( $initial, $previous ) use ( $name ) {
			$this->execOrder[] = $name;
			return $previous;
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
			 * @param SearcherFactory $searcherFactory
			 * @param SearchQuery $query
			 * @return FallbackMethod
			 */
			public static function build(
				SearcherFactory $searcherFactory,
				SearchQuery $query
			) {
				throw new AssertionFailedError();
			}

			/**
			 * @param ResultSet $firstPassResults
			 * @return float
			 */
			public function successApproximation( ResultSet $firstPassResults ) {
				return $this->prio;
			}

			/**
			 * @param ResultSet $firstPassResults results of the initial query
			 * @param ResultSet $previousSet results returned by previous fallback method
			 * @return ResultSet
			 */
			public function rewrite( ResultSet $firstPassResults, ResultSet $previousSet ) {
				Assert::assertNotNull( $this->rewritteCallback );
				return ( $this->rewritteCallback )( $previousSet, $previousSet );
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
		$runner = FallbackRunner::create( $searcherFactory, $query );
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
		$initialResults = new ResultSet( false,
			( new DefaultBuilder() )->buildResultSet( new Response( $response ), new Query() ) );
		$this->assertNotEmpty( $runner->getElasticSuggesters() );
		$newResults = $runner->run( $initialResults );
		$this->assertEquals( 2, $newResults->getTotalHits() );
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
}
