<?php

namespace CirrusSearch\Test;

use CirrusSearch;
use CirrusSearch\CirrusTestCase;
use CirrusSearch\CompletionSuggester;
use CirrusSearch\Connection;
use CirrusSearch\ElasticsearchIntermediary;
use CirrusSearch\RequestLogger;
use CirrusSearch\Searcher;
use Elastica\Request;
use Elastica\Response;
use Elastica\Transport\AbstractTransport;
use MediaWiki\MediaWikiServices;
use Psr\Log\AbstractLogger;

/**
 * Tests full text and completion search request logging. Could be expanded for
 * other request types if necessary, but these are mostly the two we care
 * about.
 *
 * @group CirrusSearch
 */
class RequestLoggerTest extends CirrusTestCase {
	public function requestLoggingProvider() {
		$tests = [];

		foreach ( glob( __DIR__ . "/fixtures/requestLogging/*.request" ) as $requestFile ) {
			$testBase = substr( $requestFile, 0, -8 );
			$testName = basename( $testBase );
			$request = $this->decodeJson( $requestFile, "Invalid request fixture" );
			$responseFile = $testBase . '.response';
			$expectedLogsFile = $testBase . '.expected';

			if ( isset( $request['_comment'] ) ) {
				$testName = "$testName - " . $request['_comment'];
			}

			if ( is_file( $expectedLogsFile ) ) {
				// Test fixtures exist. Ensure all of them exist and add the test case
				if ( !is_file( $responseFile ) ) {
					throw new \RuntimeException( "Missing response fixture: $responseFile" );
				}
				$responses = $this->decodeJson( $responseFile, "Invalid response fixture" );
				$expectedLogs = $this->decodeJson( $expectedLogsFile, "Invalid expected logs fixture" );
				$tests[$testName] = [ $request, $responses, $expectedLogs ];
			} elseif ( is_file( $responseFile ) ) {
				// have response but no expected logs, regenerate expected logs fixture
				$responses = $this->decodeJson( $responseFile, "Invalid response fixture" );
				$tests[$testName] = [ $request, $responses, $expectedLogsFile ];
			} else {
				// have neither response or expected logs, generate both fixtures
				$tests[$testName] = [ $request, $responseFile, $expectedLogsFile ];
			}
		}

		return $tests;
	}

	private function runFixture( array $query, $responses, $expectedLogs, \Closure $test ) {
		if ( is_string( $responses ) ) {
			list( $loggers, $config, $connection, $transport ) = $this->buildDependencies( null );
		} else {
			list( $loggers, $config, $connection, $transport ) = $this->buildDependencies( $responses );
		}

		// Disable opportunistic execution of deferred updates
		// in CLI mode
		$this->setMwGlobals( 'wgCommandLineMode', false );
		// This ends up breaking WebRequest::getIP(), so
		// provide an explicit value
		\RequestContext::getMain()->getRequest()->setIP( '127.0.0.1' );
		// Make sure OtherIndex is configured for use as well
		$this->setMwGlobals( 'wgCirrusSearchExtraIndexes', [
			NS_FILE => [
				'commonswiki_file'
			],
		] );
		$test( $config, $connection );

		// Force the logger to flush
		\DeferredUpdates::doUpdates();

		$logs = $this->collectLogs( $loggers );
		if ( is_string( $expectedLogs ) ) {
			// store a fixture about the generated logs
			file_put_contents( $expectedLogs, json_encode( $logs, JSON_PRETTY_PRINT ) );
			if ( is_string( $responses ) ) {
				// store a fixture about the elasticsearch response
				$responseFile = $responses;
				$responses = [];
				foreach ( $transport->getResponses() as $response ) {
					$responses[] = $response->getData();
				}
				file_put_contents( $responseFile, json_encode( $responses, JSON_PRETTY_PRINT ) );
			}
			$this->markTestSkipped( 'Stored fixtures for query' );
		} else {
			// Finally check for the expected log
			$this->assertEquals( $expectedLogs, $logs );
		}
	}

	/**
	 * @dataProvider requestLoggingProvider
	 */
	public function testRequestLogging( array $query, $responses = null, $expectedLogs ) {
		$globals = [
			'wgCirrusSearchFullTextQueryBuilderProfile' => 'default',
			'wgCirrusSearchInterwikiSources' => [],
		];
		if ( isset( $query['interwiki'] ) ) {
			$globals['wgCirrusSearchInterwikiSources'] = $query['interwiki'];
			$globals['wgCirrusSearchEnableCrossProjectSearch'] = true;
		}
		$this->setMwGlobals( $globals );

		switch ( $query['type'] ) {
		case 'fulltext':
			$work = function ( $config, $connection ) use ( $query ) {
				$offset = isset( $query['offset'] ) ? $query['offset'] : 0;
				$limit = isset( $query['limit'] ) ? $query['limit'] : 20;
				$namespaces = isset( $query['namespaces'] ) ? $query['namespaces'] : null;

				$searchEngine = new CirrusSearch( 'wiki' );
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
					if ( $log['channel'] === 'CirrusSearchRequestSet' ) {
						foreach ( $log['context']['requests'] as $reqIdx => $request ) {
							// json doesn't round trip a float with no decimal correctly,
							// so force maxscore into a float
							$expectedLogs[$logIdx]['context']['requests'][$reqIdx]['maxScore']
								= (float)$request['maxScore'];
							// elastic took ms doesn't get reported by completion api, force
							// to 0 since our cached request wont take any real-time.
							$expectedLogs[$logIdx]['context']['requests'][$reqIdx]['elasticTookMs'] = 0;
						}
					} elseif ( $log['channel'] === 'CirrusSearchRequests' ) {
						if ( isset( $log['context']['maxScore'] ) ) {
							// Again, json reound trips 0.0 into 0, so we need to get it back to being a float.
							$expectedLogs[$logIdx]['context']['maxScore'] = (float)$log['context']['maxScore'];
						}
					}
				}
			}

			$work = function ( $config, $connection ) use ( $query ) {
				$limit = isset( $query['limit'] ) ? $query['limit'] : 5;
				$offset = isset( $query['offset'] ) ? $query['offset'] : 0;
				$namespaces = isset( $query['namespaces'] ) ? $query['namespaces'] : null;
				$suggester = new CompletionSuggester( $connection, $limit, $offset, $config, $namespaces, null, 'wiki' );
				$suggester->suggest( $query['term'] );
			};
			break;

		case 'get':
			$work = function ( $config, $connection ) use ( $query ) {
				$searcher = new Searcher( $connection, 0, 20, $config, null, null, 'wiki' );
				$sourceFiltering = isset( $query['sourceFiltering'] )
					? $query['sourceFiltering']
					: true;
				$searcher->get( $query['docIds'], $sourceFiltering );
			};
			break;

		case 'findNamespace':
			$work = function ( $config, $connection ) use ( $query ) {
				$searcher = new Searcher( $connection, 0, 20, $config, null, null, 'wiki' );
				$searcher->findNamespace( $query['name'] );
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
	 */
	private function buildDependencies( $responses ) {
		// Plugin in a request logger that we know is empty
		$requestLogger = new RequestLogger;
		$requestLoggerProp = new \ReflectionProperty( ElasticsearchIntermediary::class, 'requestLogger' );
		$requestLoggerProp->setAccessible( true );
		$requestLoggerProp->setValue( $requestLogger );

		// Override the logging channel with our own so we can capture logs
		$loggers = [
			'CirrusSearchRequestSet'	=> new ArrayLogger(),
			'CirrusSearchRequests' => new ArrayLogger(),
			'CirrusSearch' => new ArrayLogger(),
		];
		foreach ( $loggers as $channel => $logger ) {
			$this->setLogger( $channel, $logger );
		}

		// Setting everything expected for running a search request/response
		// is a pain...just use the real deal and override the clusters config
		// to provide our transport.
		$config = MediaWikiServices::getInstance()
			->getConfigFactory()
			->makeConfig( 'CirrusSearch' );

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
			$transport = $this->getMockBuilder( AbstractTransport::class )
				->disableOriginalConstructor()
				->getMock();
			$transport->expects( $this->any() )
				->method( 'exec' )
				->will( new \PHPUnit_Framework_MockObject_Stub_ConsecutiveCalls(
					array_map( function ( $response ) {
						return new Response( $response, 200 );
					}, $responses )
				) );
		}

		$this->setMwGlobals( [
			'wgCirrusSearchClusters' => [
				'default' => [
					[ 'transport' => $transport ],
				]
			],
		] );
		$connection = new Connection( $config, 'default' );

		return [ $loggers, $config, $connection, $transport ];
	}

	/**
	 * Collects and filter dynamic data out of the logs that can't be
	 * statically referred to. Still asserts the keys exist, as otherwise avro
	 * would fail to encode them.
	 *
	 * @param AbstractLogger[] $loggers
	 * @return array
	 */
	private function collectLogs( array $loggers ) {
		$result = [];
		foreach ( $loggers as $channel => $logger ) {
			foreach ( $logger->getLogs() as $log ) {
				// Doing this instead of $log['channel'] = ... allows
				// channel to be at the top of the encoded output.
				$log = [
					'channel' => $channel,
				] + $log;

				if ( $channel === 'CirrusSearchRequestSet' ) {
					$log = $this->filterCSRQ( $log );
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
	 * Filter out vairable data from logs formatted for
	 * CirrusSearchRequestSet
	 *
	 * @param array $log
	 * @return log
	 */
	private function filterCSRQ( array $log ) {
		$debug = json_encode( $log, JSON_PRETTY_PRINT );
		// we need to remove some quasi-random data. To be safe
		// assert this exists before deleting it.
		foreach ( [ 'id', 'ts', 'wikiId', 'identity', 'tookMs' ] as $key ) {
			$this->assertArrayHasKey( $key, $log['context'], $debug );
			unset( $log['context'][$key] );
		}
		$this->assertArrayHasKey( 'host', $log['context']['payload'], $debug );
		unset( $log['context']['payload']['host'] );

		// Do same for the requests in the log
		foreach ( array_keys( $log['context']['requests'] ) as $idx ) {
			$this->assertArrayHasKey( 'tookMs', $log['context']['requests'][$idx], $debug );
			unset( $log['context']['requests'][$idx]['tookMs'] );
		}
		return $log;
	}

	private function decodeJson( $file, $errorMessage ) {
		$data = json_decode( file_get_contents( $file ), true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			throw new \RuntimeException( "$errorMessage: $file" );
		}

		return $data;
	}
}

class ArrayLogger extends AbstractLogger {
	private $logs = [];

	public function log( $level, $message, array $context = [] ) {
		$this->logs[] = [
			'level' => $level,
			'message' => $message,
			'context' => $context,
		];
	}

	public function getLogs() {
		return $this->logs;
	}
}

class PassThruTransport extends AbstractTransport {

	private $transportConfig;
	private $inner;
	private $responses = [];

	public function __construct( $inner ) {
		if ( $inner instanceof AbstractTransport ) {
			$this->inner = $inner;
		} else {
			$this->transportConfig = $inner;
		}
	}

	public function getResponses() {
		return $this->responses;
	}

	// Wrapped functions from AbstractTransport
	public function exec( Request $request, array $params ) {
		$response = $this->inner->exec( $request, $params );
		$this->responses[] = $response;

		return $response;
	}

	public function getConnection() {
		return $this->inner->getConnection();
	}

	public function setConnection( \Elastica\Connection $connection ) {
		if ( $this->inner ) {
			$this->inner->setConnection( $connection );
		} else {
			$this->inner = AbstractTransport::create(
				$this->transportConfig['transport'],
				$connection,
				$this->transportConfig['params']
			);
		}

		return $this;
	}

	// Wrapped functions from Param
	public function toArray() {
		return $this->inner->toArray();
	}

	public function setParam( $key, $value ) {
		$this->inner->setParam( $key, $value );

		return $this;
	}

	public function setParams( array $params ) {
		$this->inner->setParams( $params );

		return $this;
	}

	public function addParam( $key, $value ) {
		$this->inner->addParam( $key, $value );

		return $this;
	}

	public function getParam( $key ) {
		return $this->inner->getParam( $key );
	}

	public function hasParam( $key ) {
		return $this->inner->hasParam( $key );
	}

	public function getParams() {
		return $this->inner->getParams();
	}
}
