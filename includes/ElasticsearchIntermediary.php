<?php

namespace CirrusSearch;

use ConfigFactory;
use DeferredUpdates;
use Elastica\Exception\PartialShardFailureException;
use Elastica\Exception\ResponseException;
use FormatJson;
use MediaWiki\Logger\LoggerFactory;
use RequestContext;
use Status;
use User;

/**
 * Base class with useful functions for communicating with Elasticsearch.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */
class ElasticsearchIntermediary {
	/**
	 * @var Connection
	 */
	protected $connection;
	/**
	 * @var User|null user for which we're performing this search or null in the case of
	 * requests kicked off by jobs
	 */
	protected $user;
	/**
	 * @var UserTesting Reports on this requests participation in tests
	 */
	protected $ut;
	/**
	 * @var float|null start time of current request or null if none is running
	 */
	private $requestStart = null;
	/**
	 * @var string|null description of the next request to be sent to Elasticsearch or null if not yet decided
	 */
	private $description = null;
	/**
	 * @var array map of search request stats to log about the current search request
	 */
	protected $logContext = array();

	/**
	 * @var int how many millis a request through this intermediary needs to take before it counts as slow.
	 * 0 means none count as slow.
	 */
	private $slowMillis;

	/**
	 * @var array Metrics about a completed search
	 */
	private $searchMetrics = array();

	/**
	 * @var string Id identifying this php execution
	 */
	static private $executionId;

	/**
	 * @var array[]|null Result of self::getLogContext for each request in this process
	 */
	static private $logContexts;

	/**
	 * Constructor.
	 *
	 * @param User|null $user user for which this search is being performed.  Attached to slow request logs.  Note that
	 * null isn't for anonymous users - those are still User objects and should be provided if possible.  Null is for
	 * when the action is being performed in some context where the user that caused it isn't available.  Like when an
	 * action is being performed during a job.
	 * @param float $slowSeconds how many seconds a request through this intermediary needs to take before it counts as
	 * slow.  0 means none count as slow.
	 */
	protected function __construct( Connection $connection, User $user = null, $slowSeconds ) {
		$this->connection = $connection;
		$this->user = $user;
		$this->slowMillis = round( 1000 * $slowSeconds );
		$this->ut = UserTesting::getInstance();
		$config = ConfigFactory::getDefaultInstance()->makeConfig( 'CirrusSearch' );
	}

	/**
	 * Identifies a specific execution of php.  That might be one web
	 * request, or multiple jobs run in the same executor. An execution id
	 * is valid over a brief timespan, perhaps a minute or two for some jobs.
	 *
	 * @return integer unique identifier
	 */
	private static function getExecutionId() {
		if ( self::$executionId === null ) {
			self::$executionId = mt_rand();
		}
		return self::$executionId;
	}

	/**
	 * Summarizes all the requests made in this process and reports
	 * them along with the test they belong to.
	 * Only public due to php 5.3 not having access from closures
	 */
	public static function reportLogContexts() {
		global $wgRequest;

		if ( !self::$logContexts ) {
			return;
		}
		$ut = UserTesting::getInstance();
		if ( !$ut->getActiveTestNames() ) {
			return;
		}
		$queries = array();
		$parameters = array( 'index' => array(), 'queryType' => array() );
		$elasticTook = 0;
		$hits = 0;
		foreach ( self::$logContexts as $context ) {
			$hits += isset( $context['hitsTotal'] ) ? $context['hitsTotal'] : 0;
			if ( isset( $context['query'] ) ) {
				$queries[] = $context['query'];
			}
			if ( isset( $context['elasticTookMs'] ) ) {
				$elasticTook += $context['elasticTookMs'];
			}
			if ( isset( $context['index'] ) ) {
				$parameters['index'][] = $context['index'];
			}
			if ( isset( $context['queryType'] ) ) {
				$parameters['queryType'][] = $context['queryType'];
			}
		}

		foreach ( array( 'index', 'queryType' ) as $key ) {
			$parameters[$key] = array_unique( $parameters[$key] );
		}

		$message = array(
			wfWikiId(),
			'',
			FormatJson::encode( $queries ),
			$hits,
			self::getExecutionContext(),
			$elasticTook,
			$wgRequest->getIP(),
			preg_replace( "/[\t\"']/", "", $wgRequest->getHeader( 'User-Agent') ),
			FormatJson::encode( $parameters ),
		);

		$tests = array();
		$logger = LoggerFactory::getInstance( 'CirrusSearchUserTesting' );
		foreach ( $ut->getActiveTestNames() as $test ) {
			$bucket = $ut->getBucket( $test );
			$message[1] = "{$test}-{$bucket}";
			$logger->debug( implode( "\t", $message ) );
		}
		self::$logContexts = null;
	}

	/**
	 * Mark the start of a request to Elasticsearch.  Public so it can be called from pool counter methods.
	 *
	 * @param string $description name of the action being started
	 * @param array $logContext Contextual variables for generating log messages
	 */
	public function start( $description, array $logContext = array() ) {
		$this->description = $description;
		$this->logContext = $logContext;
		$this->requestStart = microtime( true );
	}

	/**
	 * Log a successful request and return the provided result in a good Status.  If you don't need the status
	 * just ignore the return.  Public so it can be called from pool counter methods.
	 *
	 * @param mixed $result result of the request.  defaults to null in case the request doesn't have a result
	 * @return \Status wrapping $result
	 */
	public function success( $result = null ) {
		$this->finishRequest();
		return Status::newGood( $result );
	}

	/**
	 * Log a failure and return an appropriate status.  Public so it can be called from pool counter methods.
	 *
	 * @param \Elastica\Exception\ExceptionInterface|null $exception if the request failed
	 * @return \Status representing a backend failure
	 */
	public function failure( $exception = null ) {
		$context = $this->logContext;
		$context['took'] = $this->finishRequest();
		list( $status, $message ) = $this->extractMessageAndStatus( $exception );
		$context['message'] = $message;

		$stats = RequestContext::getMain()->getStats();
		$type = self::classifyErrorMessage( $message );
		$stats->increment( "cirrussearch.backend_failure.$type" );

		LoggerFactory::getInstance( 'CirrusSearch' )->warning(
			"Search backend error during {$this->description} after {took}: {message}",
			$context
		);
		return $status;
	}

	/**
	 * Broadly classify the error message into failures where
	 * we decided to not serve the query, and failures where
	 * we just failed to answer
	 *
	 * @param string $message Extracted exception message from
	 *  self::extractMessageAndStatus
	 * @return string Either 'rejected', 'failed' or 'unknown'
	 */
	static public function classifyErrorMessage( $message ) {
		$rejected = implode( '|', array_map( 'preg_quote', array(
			'Regex syntax error: ',
			'Determinizing automaton would ',
			'Parse error on ',
			'Failed to parse source ',
			'SearchParseException',
			'org.apache.lucene.queryparser.classic.Token',
			'IllegalArgumentException',
			'TooManyClauses'
		) ) );
		if ( preg_match( "/$rejected/", $message ) ) {
			return 'rejected';
		}

		$failed = implode( '|', array_map( 'preg_quote', array(
			'rejected execution (queue capacity',
			'Operation timed out',
			'RemoteTransportException',
			'Couldn\'t connect to host',
			'No enabled connection',
			'SearchContextMissingException',
			'NullPointerException',
		) ) );
		if ( preg_match( "/$failed/", $message ) ) {
			return 'failed';
		}

		return "unknown";
	}

	/**
	 * Get the search metrics we have
	 * @return array
	 */
	public function getSearchMetrics() {
		return $this->searchMetrics;
	}

	/**
	 * Extract an error message from an exception thrown by Elastica.
	 * @param RuntimeException $exception exception from which to extract a message
	 * @return string message from the exception
	 */
	public static function extractMessage( $exception ) {
		if ( !( $exception instanceof ResponseException ) ) {
			return $exception->getMessage();
		}
		if ( $exception instanceof PartialShardFailureException ) {
			$shardStats = $exception->getResponse()->getShardsStatistics();
			$message = array();
			foreach ( $shardStats[ 'failures' ] as $failure ) {
				$message[] = $failure[ 'reason' ];
			}
			return 'Partial failure:  ' . implode( ',', $message );
		}
		return $exception->getElasticsearchException()->getMessage();
	}

	/**
	 * Does this status represent an Elasticsearch parse error?
	 * @param $status Status to check
	 * @return boolean is this a parse error?
	 */
	protected function isParseError( $status ) {
		foreach ( $status->getErrorsArray() as $errorMessage ) {
			if ( $errorMessage[ 0 ] === 'cirrussearch-parse-error' ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Log the completion of a request to Elasticsearch.
	 * @return int number of milliseconds it took to complete the request
	 */
	private function finishRequest() {
		global $wgCirrusSearchLogElasticRequests;

		if ( !$this->requestStart ) {
			LoggerFactory::getInstance( 'CirrusSearch' )->warning(
				'finishRequest called without staring a request'
			);
			return;
		}
		$endTime = microtime( true );
		$took = round( ( $endTime - $this->requestStart ) * 1000 );

		RequestContext::getMain()->getStats()->timing( 'CirrusSearch.requestTime', $took );
		$this->searchMetrics['wgCirrusStartTime'] = $this->requestStart;
		$this->searchMetrics['wgCirrusEndTime'] = $endTime;
		$logContext = $this->buildLogContext( $took );
		if ( isset( $logContext['elasticTookMs'] ) ) {
			$this->searchMetrics['wgCirrusElasticTime'] = $logContext['elasticTookMs'];
		}
		if ( $wgCirrusSearchLogElasticRequests ) {
			$logMessage = $this->buildLogMessage( $logContext );
			LoggerFactory::getInstance( 'CirrusSearchRequests' )->debug( $logMessage, $logContext );
			if ( $this->slowMillis && $took >= $this->slowMillis ) {
				if ( $this->user ) {
					$logContext['user'] = $this->user->getName();
					$logMessage .= ' for {user}';
				}
				LoggerFactory::getInstance( 'CirrusSearchSlowRequests' )->info( $logMessage, $logContext );
			}
		}
		$this->requestStart = null;
		return $took;
	}

	/**
	 * @param array $context Request specific log variables from self::buildLogContext()
	 * @return string a PSR-3 compliant message describing $context
	 */
	private function buildLogMessage( $context ) {
		// No need to check description because it must be set by $this->start.
		$message = $this->description;
		$message .= " against {index} took {tookMs} millis";
		if ( isset( $context['elasticTookMs'] ) ) {
			$message .= " and {elasticTookMs} Elasticsearch millis";
			if ( isset( $context['elasticTook2PassMs'] ) ) {
				$message .= " (with 2nd pass: {elasticTook2PassMs} ms)";
			}
		}
		if ( isset( $context['hitsTotal'] ) ){
			$message .= ". Found {hitsTotal} total results";
			$message .= " and returned {hitsReturned} of them starting at {hitsOffset}";
		}
		if ( isset( $context['namespaces'] ) ) {
			$namespaces = implode( ', ', $context['namespaces'] );
			$message .= " within these namespaces: $namespaces";
		}
		if ( isset( $context['suggestion'] ) ) {
			$message .= " and suggested '{suggestion}'";
		}
		$message .= ". Requested via {source} for {identity} by executor {executor}";

		return $message;
	}

	/**
	 * These values end up serialized into Avro which has strict typing
	 * requirements. float !== int !== string.
	 *
	 * @param float $took Number of milliseconds the request took
	 * @return array
	 */
	private function buildLogContext( $took ) {
		$client = $this->connection->getClient();
		$query = $client->getLastRequest();
		$result = $client->getLastResponse();

		$params = $this->logContext;
		$this->logContext = array();

		$params += array(
			'tookMs' => intval( $took ),
			'source' => self::getExecutionContext(),
			'executor' => self::getExecutionId(),
			'identity' => self::generateIdentToken(),
		);

		if ( $result ) {
			$queryData = $query->getData();
			$resultData = $result->getData();

			$index = explode( '/', $query->getPath() );
			$params['index'] = $index[0];
			if ( isset( $resultData[ 'took' ] ) ) {
				$elasticTook = $resultData[ 'took' ];
				$params['elasticTookMs'] = intval( $elasticTook );
			}
			if ( isset( $resultData['hits']['total'] ) ) {
				$params['hitsTotal'] = intval( $resultData['hits']['total'] );
			}
			if ( isset( $resultData['hits']['hits'] ) ) {
				$num = count( $resultData['hits']['hits'] );
				$offset = isset( $queryData['from'] ) ? $queryData['from'] : 0;
				$params['hitsReturned'] = $num;
				$params['hitsOffset'] = intval( $offset );
			}
			if ( $this->_isset( $queryData, array( 'query', 'filtered', 'filter', 'terms', 'namespace' ) ) ) {
				$namespaces = $queryData['query']['filtered']['filter']['terms']['namespace'];
				$params['namespaces'] = array_map( 'intval', $namespaces );
			}
			if ( isset( $resultData['suggest']['suggest'][0]['options'][0]['text'] ) ) {
				$params['suggestion'] = $resultData['suggest']['suggest'][0]['options'][0]['text'];
			}
		}

		if ( self::$logContexts === null ) {
			DeferredUpdates::addCallableUpdate( function () {
				ElasticsearchIntermediary::reportLogContexts();
			} );
			self::$logContexts = array();
		}
		self::$logContexts[] = $params;

		return $params;
	}

	/**
	 * @return string The context the request is in. Either cli, api or web.
	 */
	static public function getExecutionContext() {
		if ( php_sapi_name() === 'cli' ) {
			return 'cli';
		} elseif ( defined( 'MW_API' ) ) {
			return 'api';
		} else {
			return 'web';
		}
	}

	private function extractMessageAndStatus( $exception ) {
		if ( !$exception ) {
			return array( Status::newFatal( 'cirrussearch-backend-error' ), '' );
		}

		// Lots of times these are the same as getMessage(), but sometimes
		// they're not. So get the nested exception so we're sure we get
		// what we want. I'm looking at you PartialShardFailureException.
		$message = self::extractMessage( $exception );

		$marker = 'ParseException[Cannot parse ';
		$markerLocation = strpos( $message, $marker );
		if ( $markerLocation !== false ) {
			// The important part of the parse error message comes before the next new line
			// so lets slurp it up and log it rather than the huge clump of error.
			$start = $markerLocation + strlen( $marker );
			$end = strpos( $message, "\n", $start );
			$parseError = substr( $message, $start, $end - $start );
			return array( Status::newFatal( 'cirrussearch-parse-error' ), 'Parse error on ' . $parseError );
		}

		$marker = 'Determinizing';
		$markerLocation = strpos( $message, $marker );
		if ( $markerLocation !== false ) {
			$startOfMessage = $markerLocation;
			$endOfMessage = strpos( $message, ']; nested', $startOfMessage );
			if ( $endOfMessage === false ) {
				$endOfMessage = strpos( $message, '; Determinizing', $startOfMessage );
			}
			$extracted = substr( $message, $startOfMessage, $endOfMessage - $startOfMessage );
			return array( Status::newFatal( 'cirrussearch-regex-too-complex-error' ), $extracted );
		}
		// This is _probably_ a regex syntax error so lets call it that. I can't think of
		// what else would have automatons and illegal argument exceptions. Just looking
		// for the exception won't suffice because other weird things could cause it.
		$seemsToUseRegexes = strpos( $message, 'import org.apache.lucene.util.automaton.*' ) !== false;
		$usesExtraRegex = strpos( $message, 'source_text:/' ) !== false;
		$seemsToUseRegexes |= $usesExtraRegex;
		$marker = 'IllegalArgumentException[';
		$markerLocation = strpos( $message, $marker );
		if ( $seemsToUseRegexes && $markerLocation !== false ) {
			$start = $markerLocation + strlen( $marker );
			$end = strpos( $message, "];", $start );
			$syntaxError = substr( $message, $start, $end - $start );
			$errorMessage = 'unknown';
			$position = 'unknown';
			$matches = array();
			if ( preg_match( '/(.+) at position (\d+)/', $syntaxError, $matches ) ) {
				$errorMessage = $matches[ 1 ];
				$position = $matches[ 2 ];
				if ( !$usesExtraRegex ) {
					// The 3 below offsets the .*( in front of the user pattern
					// to make it unanchored.
					$position -= 3;
				}
			} else if ( $syntaxError === 'unexpected end-of-string' ) {
				$errorMessage = 'regex too short to be correct';
			}
			$status = Status::newFatal( 'cirrussearch-regex-syntax-error', $errorMessage, $position );
			return array( $status, 'Regex syntax error:  ' . $syntaxError );
		}
		return array( Status::newFatal( 'cirrussearch-backend-error' ), $message );
	}

	/**
	 * @param string $extraData Extra information to mix into the hash
	 * @return string A token that identifies the source of the request
	 */
	public static function generateIdentToken( $extraData = '' ) {
		$request = \RequestContext::getMain()->getRequest();
		return md5( implode( ':', array(
			$extraData,
			$request->getIP(),
			$request->getHeader( 'X-Forwarded-For' ),
			$request->getHeader( 'User-Agent' ),
		) ) );
	}

	/**
	 * Like isset, but wont fatal when one of the expected array keys in a
	 * multi-dimensional array is a string.
	 *
	 * Temporary hack required only for php 5.3. Can be removed when 5.4 is no
	 * longer a requirement.  See T99871 for more details.
	 *
	 * @param array $array
	 * @param array $path
	 * @return bool
	 */
	private function _isset( $array, $path ) {
		while( true ) {
			$step = array_shift( $path );
			if ( !isset( $array[$step] ) ) {
				// next step of the path is non-existent
				return false;
			} elseif( !$path ) {
				// reached the end of our path
				return true;
			} elseif ( !is_array( $array[$step] ) ) {
				// more steps exist in the path, but we don't have an array
				return false;
			} else {
				// keep looking
				$array = $array[$step];
			}
		}
	}
}
