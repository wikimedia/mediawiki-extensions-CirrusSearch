<?php

namespace CirrusSearch\Test;

use CirrusSearch\CirrusIntegrationTestCase;
use CirrusSearch\CirrusSearch;
use CirrusSearch\CompletionSuggester;
use CirrusSearch\Connection;
use CirrusSearch\ElasticsearchIntermediary;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Hooks;
use CirrusSearch\RequestLog;
use CirrusSearch\RequestLogger;
use CirrusSearch\SearchConfig;
use CirrusSearch\Searcher;
use Elastica\Response;
use Elastica\Transport\AbstractTransport;
use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use Psr\Log\AbstractLogger;
use Symfony\Component\Yaml\Yaml;
use Wikimedia\TestingAccessWrapper;

/**
 * Tests full text and completion search request logging. Could be expanded for
 * other request types if necessary, but these are mostly the two we care
 * about.
 *
 * @group CirrusSearch
 * @covers \CirrusSearch\RequestLogger
 * @covers \CirrusSearch\ElasticsearchIntermediary
 * @covers \CirrusSearch\Hooks::prefixSearchExtractNamespaceWithConnection()
 */
class RequestLoggerTest extends CirrusIntegrationTestCase {
	/** @var array mediawiki/cirrussearch/request schema */
	private $schema;

	protected function setUp(): void {
		parent::setUp();

		$schemaPath = self::$FIXTURE_DIR . 'requestLogging/mediawiki_cirrussearch_request.schema.yaml';
		$this->schema = Yaml::parseFile( $schemaPath );
		// Clear all hooks; some from the GrowthExperiments extension can cause DB queries.
		$this->clearHooks();
	}

	public function testHasQueryLogs() {
		// Prevent Deferred updates from running. This basically means RequestLogger is
		// broken for cli scripts (but considered not too important).
		$cleanup = DeferredUpdates::preventOpportunisticUpdates();

		$logger = new RequestLogger();
		$this->assertFalse( $logger->hasQueryLogs() );
		$log = $this->createMock( RequestLog::class );
		foreach ( [ 'getLogVariables', 'getRequests' ] as $fn ) {
			$log->method( $fn )
				->willReturn( [] );
		}
		$logger->addRequest( $log );
		$this->assertTrue( $logger->hasQueryLogs() );
	}

	public static function requestLoggingProvider() {
		foreach ( CirrusIntegrationTestCase::findFixtures( 'requestLogging/*.request' ) as $requestFile ) {
			$testBase = substr( $requestFile, 0, -8 );
			$testName = basename( $testBase );
			$request = CirrusIntegrationTestCase::loadFixture( $requestFile );
			$responseFile = $testBase . '.response';
			$expectedLogsFile = $testBase . '.expected';

			if ( isset( $request['_comment'] ) ) {
				$testName = "$testName - " . $request['_comment'];
			}

			if ( CirrusIntegrationTestCase::hasFixture( $expectedLogsFile ) ) {
				// Test fixtures exist. Ensure all of them exist and add the test case
				if ( !CirrusIntegrationTestCase::hasFixture( $responseFile ) ) {
					throw new \RuntimeException( "Missing response fixture: $responseFile" );
				}
				$responses = CirrusIntegrationTestCase::loadFixture( $responseFile, "response fixture" );
				$expectedLogs = CirrusIntegrationTestCase::loadFixture( $expectedLogsFile, "expected logs fixture" );
				yield $testName => [ $request, $responses, $expectedLogs ];
			} elseif ( CirrusIntegrationTestCase::hasFixture( $responseFile ) ) {
				// have response but no expected logs, regenerate expected logs fixture
				$responses = CirrusIntegrationTestCase::loadFixture( $responseFile, "response fixture" );
				yield $testName => [ $request, $responses, $expectedLogsFile ];
			} else {
				// have neither response or expected logs, generate both fixtures
				yield $testName => [ $request, $responseFile, $expectedLogsFile ];
			}
		}
	}

	private function runFixture( array $query, $responses, $expectedLogs, \Closure $test ) {
		if ( is_string( $responses ) ) {
			[ $loggers, $config, $connection, $transport ] = $this->buildDependencies( null );
		} else {
			[ $loggers, $config, $connection, $transport ] = $this->buildDependencies( $responses );
		}

		$this->overrideConfigValues( [
			// Default config of SiteMatrix in vagrant is broken
			'SiteMatrixSites' => [],
			// Make sure OtherIndex is configured for use as well
			'CirrusSearchExtraIndexes' => [ NS_FILE => [ 'commonswiki_file' ] ],
		] );

		// Disable opportunistic execution of deferred updates
		$cleanup = DeferredUpdates::preventOpportunisticUpdates();
		$test( $config, $connection );
		// Force the logger to flush
		DeferredUpdates::doUpdates();

		$logs = $this->collectLogs( $loggers );

		if ( is_string( $expectedLogs ) ) {
			// store a fixture about the generated logs
			CirrusIntegrationTestCase::saveFixture( $expectedLogs, $logs );
			if ( is_string( $responses ) ) {
				// store a fixture about the elasticsearch response
				$responseFile = $responses;
				$responses = [];
				foreach ( $transport->getResponses() as $response ) {
					$responses[] = $response->getData();
				}
				CirrusIntegrationTestCase::saveFixture( $responseFile, $responses );
			}
			$this->markTestSkipped( 'Stored fixtures for query' );
		} else {
			// Finally check for the expected log
			$this->assertEquals( $expectedLogs, $logs, json_encode( $logs, JSON_PRETTY_PRINT ) );
		}
	}

	/**
	 * @dataProvider requestLoggingProvider
	 */
	public function testRequestLogging( array $query, $responses, $expectedLogs ) {
		$configs = [
			'CirrusSearchFullTextQueryBuilderProfile' => 'default',
			'CirrusSearchInterwikiSources' => [],
			'CirrusSearchNamespaceResolutionMethod' => 'elastic',
		];
		if ( isset( $query['interwiki'] ) ) {
			$configs['CirrusSearchInterwikiSources'] = $query['interwiki'];
			$configs['CirrusSearchEnableCrossProjectSearch'] = true;
		}
		$this->overrideConfigValues( $configs );
		$this->setService( 'LinkBatchFactory', $this->createMock( LinkBatchFactory::class ) );
		$titleFactory = $this->createMock( TitleFactory::class );
		$titleFactory->method( 'makeTitle' )->willReturnCallback( static function ( $ns, $title, $fragment = '', $interwiki = '' ) {
			$ret = Title::makeTitle( $ns, $title, $fragment, $interwiki );
			$ret->resetArticleID( 0 );
			return $ret;
		} );
		$this->setService( 'TitleFactory', $titleFactory );

		switch ( $query['type'] ) {
			case 'fulltext':
				$work = static function ( $config, $connection ) use ( $query ) {
					$offset = $query['offset'] ?? 0;
					$limit = $query['limit'] ?? 20;
					$namespaces = $query['namespaces'] ?? null;
					$config = new HashSearchConfig(
						[ SearchConfig::INDEX_BASE_NAME => 'wiki' ],
						[ HashSearchConfig::FLAG_INHERIT ] );
					$searchEngine = new CirrusSearch( $config );
					$searchEngine->setConnection( $connection );
					$searchEngine->setLimitOffset( $limit, $offset );
					$searchEngine->setNamespaces( $namespaces );
					$searchEngine->setShowSuggestion( $query['showSuggestion'] );
					if ( isset( $query['sort'] ) ) {
						$searchEngine->setSort( $query['sort'] );
					}
					$searchEngine->searchText( $query['term'] );
				};
				break;

			case 'completion':
				if ( is_array( $expectedLogs ) ) {
					foreach ( $expectedLogs as $logIdx => $log ) {
						if ( $log['channel'] === 'CirrusSearchRequests' ) {
							if ( isset( $log['context']['maxScore'] ) ) {
								// Again, json reound trips 0.0 into 0, so we need to get it back to being a float.
								$expectedLogs[$logIdx]['context']['maxScore'] = (float)$log['context']['maxScore'];
							}
						}
					}
				}

				$work = static function ( $config, $connection ) use ( $query ) {
					$limit = $query['limit'] ?? 5;
					$offset = $query['offset'] ?? 0;
					$namespaces = $query['namespaces'] ?? null;
					$suggester = new CompletionSuggester( $connection, $limit, $offset, $config, $namespaces, null, 'wiki' );
					$suggester->suggest( $query['term'] );
				};
				break;

			case 'get':
				$work = static function ( $config, $connection ) use ( $query ) {
					$searcher = new Searcher( $connection, 0, 20, $config, null, null, 'wiki' );
					$sourceFiltering = $query['sourceFiltering'] ?? true;
					$searcher->get( $query['docIds'], $sourceFiltering );
				};
				break;

			default:
				throw new \RuntimeException( "Unknown request type: " . $query['type'] );
		}

		$this->runFixture( $query, $responses, $expectedLogs, $work );
	}

	/**
	 * Build the necessary dependencies to use Searcher to return a specified
	 * response.
	 * @param array|null $responses
	 * @return array
	 */
	private function buildDependencies( $responses ) {
		// Plugin in a request logger that we know is empty
		$requestLogger = new RequestLogger;
		TestingAccessWrapper::newFromClass( ElasticsearchIntermediary::class )->requestLogger = $requestLogger;

		// Override the logging channel with our own so we can capture logs
		$loggers = [
			'cirrussearch-request' => new ArrayLogger(),
			'CirrusSearchRequests' => new ArrayLogger(),
			'CirrusSearch' => new ArrayLogger(),
		];
		foreach ( $loggers as $channel => $logger ) {
			$this->setLogger( $channel, $logger );
		}

		// Setting everything expected for running a search request/response
		// is a pain...just use the real deal and override the clusters config
		// to provide our transport.
		$config = SearchConfig::newFromGlobals();
		if ( $responses === null ) {
			// Build up an elastica transport that will record responses
			// so they can be stored as fixtures.
			$oldConnection = new Connection( $config, 'default' );
			// necessary if config is using the pooled http/https classes (unlikely?)
			$serverList = $oldConnection->getServerList();
			if ( is_string( $serverList[0] ) ) {
				$innerTransport = [ 'params' => [ 'host' => $serverList[0] ], 'transport' => 'Http' ];
			} else {
				$innerTransport = $serverList[0]['transport'];
			}
			$transport = new PassThruTransport( $innerTransport );
		} else {
			// Build up an elastica transport that will return our faked response
			$transport = $this->createMock( AbstractTransport::class );
			$transport->method( 'exec' )
				->willReturnOnConsecutiveCalls(
					...array_map( static function ( $response ) {
						return new Response( $response, 200 );
					}, $responses )
				);
		}

		$this->overrideConfigValues( [
			'CirrusSearchClusters' => [
				'default' => [
					[ 'transport' => $transport ],
				]
			],
			'CirrusSearchNamespaceResolutionMethod' => 'utr30',
		] );
		$connection = new Connection( $config, 'default' );
		$this->setTemporaryHook( 'PrefixSearchExtractNamespace',
			static function ( &$namespace, &$query ) use ( $connection ) {
				return Hooks::prefixSearchExtractNamespaceWithConnection( $connection,
					$namespace, $query );
			}
		);
		return [ $loggers, $config, $connection, $transport ];
	}

	/**
	 * Collects and filter dynamic data out of the logs that can't be
	 * statically referred to.
	 *
	 * @param AbstractLogger[] $loggers
	 * @return array
	 */
	private function collectLogs( array $loggers ) {
		$result = [];
		foreach ( $loggers as $channel => $logger ) {
			if ( !in_array( $channel, [ 'cirrussearch-request', 'CirrusSearchRequests' ] ) ) {
				// Only look at the specific logging channels we are testing.
				// Other logs may be generated but have nothing to do with
				// structured request logging.
				continue;
			}
			foreach ( $logger->getLogs() as $log ) {
				// Doing this instead of $log['channel'] = ... allows
				// channel to be at the top of the encoded output.
				$log = [
					'channel' => $channel,
				] + $log;

				if ( $channel == 'cirrussearch-request' ) {
					// Before we filter this log for testing against fixture
					// data, we should make sure that the event in
					// $log['context'] validates against the expected
					// mediwiki/cirrussearch/request event JSONSchema. If the
					// JSONSchema has changed, you'll need to make sure the
					// schema file in the fixtures/ directory is also updated.
					$validator = new \JsonSchema\Validator;
					$fixed = json_decode( json_encode( $log['context'] ) );
					$validator->validate( $fixed, $this->schema );

					$errors = [];
					foreach ( $validator->getErrors() as $error ) {
						$errors[] = sprintf( "[%s] %s\n", $error['property'], $error['message'] );
					}
					$this->assertTrue( $validator->isValid(), implode( '\n', $errors ) );

					// Now return the filtered requestset event for fixture testing.
					$log = $this->filterCirrusSearchRequestEvent( $log );
				} else {
					$log = $this->filterGeneralLog( $log );
				}
				$result[] = $this->reorderLog( $log );
			}
		}

		return $result;
	}

	/**
	 * Put the log into a stable order, so generating new fixtures
	 * doesn't change parts of the log that only moved, but were
	 * not changed
	 *
	 * @param array $log
	 * @return array
	 */
	private function reorderLog( array $log ) {
		$keys = array_keys( $log );
		if ( is_string( reset( $keys ) ) ) {
			ksort( $log );
		}
		foreach ( $log as $k => $v ) {
			if ( is_array( $v ) ) {
				$log[$k] = $this->reorderLog( $v );
			}
		}

		return $log;
	}

	/**
	 * Filter out variable data from "standard" log messages. This isn't particularly
	 * stringent because these logs are read by humans and not machines.
	 *
	 * @param array $log
	 * @return log
	 */
	private function filterGeneralLog( array $log ) {
		if ( isset( $log['context']['tookMs'] ) ) {
			$log['context']['tookMs'] = 0;
		}
		if ( isset( $log['context']['elasticTookMs'] ) ) {
			$log['context']['elasticTookMs'] = 0;
		}
		if ( isset( $log['context']['executor'] ) ) {
			$log['context']['executor'] = '123456789';
		}
		return $log;
	}

	/**
	 * Filter out variable data from logs formatted for
	 * cirrussearch-request event
	 *
	 * @param array $log
	 * @return log
	 */
	private function filterCirrusSearchRequestEvent( array $log ) {
		$debug = json_encode( $log, JSON_PRETTY_PRINT );
		// we need to remove some quasi-random data. To be safe
		// assert this exists before deleting it.
		foreach (
			[
				'meta', 'database', 'mediawiki_host', 'identity',
				'request_time_ms', 'search_id'
			] as $key
		) {
			$this->assertArrayHasKey( $key, $log['context'], $debug );
			unset( $log['context'][$key] );
		}

		// Do same for the requests in the log
		foreach ( array_keys( $log['context']['elasticsearch_requests'] ) as $idx ) {
			$this->assertArrayHasKey( 'request_time_ms', $log['context']['elasticsearch_requests'][$idx], $debug );
			unset( $log['context']['elasticsearch_requests'][$idx]['request_time_ms'] );
		}
		return $log;
	}
}
