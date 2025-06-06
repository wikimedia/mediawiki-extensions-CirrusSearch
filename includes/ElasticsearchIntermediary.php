<?php

namespace CirrusSearch;

use CirrusSearch\Search\SearchMetricsProvider;
use Elastica\Exception\ExceptionInterface;
use Elastica\Exception\ResponseException;
use Elastica\Exception\RuntimeException;
use Elastica\Multi\ResultSet as MultiResultSet;
use Elastica\Multi\Search;
use Elastica\Response;
use ISearchResultSet;
use MediaWiki\Config\ConfigException;
use MediaWiki\Context\RequestContext;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Status\Status;
use MediaWiki\User\UserIdentity;
use Wikimedia\Assert\Assert;

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
abstract class ElasticsearchIntermediary {
	/**
	 * @var Connection
	 */
	protected $connection;

	/**
	 * @var UserIdentity|null user for which we're performing this search or null in
	 * the case of requests kicked off by jobs
	 */
	protected $user;

	/**
	 * @var RequestLog|null Log for in-progress search request
	 */
	protected $currentRequestLog = null;

	/**
	 * @var int how many millis a request through this intermediary needs to
	 * take before it counts as slow. 0 means none count as slow.
	 */
	private $slowMillis;

	/**
	 * @var array Metrics about a completed search
	 */
	private $searchMetrics = [];

	/**
	 * @var int artificial extra backend latency in micro seconds
	 */
	private $extraBackendLatency;

	/**
	 * @var RequestLogger
	 */
	protected static $requestLogger;

	/**
	 * @param Connection $connection
	 * @param UserIdentity|null $user user for which this search is being performed.
	 *  Attached to slow request logs.  Note that null isn't for anonymous users
	 *  - those are still User objects and should be provided if possible.  Null
	 *  is for when the action is being performed in some context where the user
	 *  that caused it isn't available.  Like when an action is being performed
	 *  during a job.
	 * @param float|null $slowSeconds how many seconds a request through this
	 *  intermediary needs to take before it counts as slow.  0 means none count
	 *  as slow. Defaults to CirrusSearchSlowSearch config option.
	 * @param int $extraBackendLatency artificial backend latency.
	 */
	protected function __construct( Connection $connection, ?UserIdentity $user = null, $slowSeconds = null, $extraBackendLatency = 0 ) {
		$this->connection = $connection;
		$this->user = $user ?? RequestContext::getMain()->getUser();
		$this->slowMillis = (int)( 1000 * ( $slowSeconds ?? $connection->getConfig()->get( 'CirrusSearchSlowSearch' ) ) );
		$this->extraBackendLatency = $extraBackendLatency;
		if ( self::$requestLogger === null ) {
			self::$requestLogger = new RequestLogger;
		}
		// This isn't explicitly used, but we need to make sure it is
		// instantiated so it has the opportunity to override global
		// configuration for test buckets.
		UserTestingStatus::getInstance();
	}

	/**
	 * This is set externally because we don't have complete control, from the
	 * SearchEngine interface, of what is actually sent to the user. Instead hooks
	 * receive the final results that will be sent to the user and set them here.
	 *
	 * Accepts two result sets because some places (Special:Search) perform multiple
	 * searches. This can be called multiple times, but only that last call wins. For
	 * API's that is correct, for Special:Search a hook catches the final results and
	 * sets them here.
	 *
	 * @param ISearchResultSet[] $matches
	 */
	public static function setResultPages( array $matches ) {
		if ( self::$requestLogger === null ) {
			// This could happen if Cirrus is not the active engine,
			// but the hook is still loaded. In this case, do nothing.
			return;
		} else {
			self::$requestLogger->setResultPages( $matches );
		}
	}

	/**
	 * Report the types of queries that were issued
	 * within the current request.
	 *
	 * @return string[]
	 */
	public static function getQueryTypesUsed() {
		if ( self::$requestLogger === null ) {
			// This can happen when, for example, completion search is
			// triggered against NS_SPECIAL, where searching is done strictly
			// in PHP and never actually creates a  SearchEngine.
			return [];
		} else {
			return self::$requestLogger->getQueryTypesUsed();
		}
	}

	/**
	 * @return bool True when query logs have been generated by the
	 *  current php execution.
	 */
	public static function hasQueryLogs() {
		if ( self::$requestLogger === null ) {
			return false;
		}
		return self::$requestLogger->hasQueryLogs();
	}

	/**
	 * Mark the start of a request to Elasticsearch.  Public so it can be
	 * called from pool counter methods.
	 */
	public function start( RequestLog $log ) {
		$this->currentRequestLog = $log;
		$log->start();
		if ( $this->extraBackendLatency ) {
			usleep( $this->extraBackendLatency );
		}
	}

	/**
	 * Log a successful request and return the provided result in a good
	 * Status.  If you don't need the status just ignore the return.  Public so
	 * it can be called from pool counter methods.
	 *
	 * @param mixed|null $result result of the request.  defaults to null in case
	 *  the request doesn't have a result
	 * @param Connection|null $connection The connection the succesful
	 *  request was performed against. Will use $this->connection when not
	 *  provided.
	 * @return Status wrapping $result
	 */
	public function success( $result = null, ?Connection $connection = null ) {
		$this->finishRequest( $connection ?? $this->connection );
		return Status::newGood( $result );
	}

	/**
	 * Log a successful request when the response comes from a cache outside
	 * elasticsearch. This is a combination of self::start() and self::success().
	 */
	public function successViaCache( RequestLog $log ) {
		if ( $this->extraBackendLatency ) {
			usleep( $this->extraBackendLatency );
		}
		self::$requestLogger->addRequest( $log );
	}

	/**
	 * Log a failure and return an appropriate status.  Public so it can be
	 * called from pool counter methods.
	 *
	 * @param ExceptionInterface|null $exception if the request failed
	 * @param Connection|null $connection The connection that the failed
	 *  request was performed against. Will use $this->connection when not
	 *  provided.
	 * @return Status representing a backend failure
	 */
	public function failure( ?ExceptionInterface $exception = null, ?Connection $connection = null ) {
		$connection ??= $this->connection;
		$log = $this->finishRequest( $connection );
		if ( $log === null ) {
			// Request was never started, likely trying to close a request
			// a second time. If so that was already logged by finishRequest.
			$context = [];
			$logType = 'not_started';
		} else {
			$context = $log->getLogVariables();
			$logType = $log->getDescription();
		}
		[ $status, $message ] = ElasticaErrorHandler::extractMessageAndStatus( $exception );
		// This could be multiple MB if the failure is coming from an update
		// script, as the whole update script is returned in the error
		// including the parameters. Truncate to a reasonable level so
		// downstream log processing doesn't truncate them (and then fail to
		// parse the truncated json). Take the first 4k to leave plenty of room for
		// whatever else.
		$context['error_message'] = mb_substr( $message, 0, 4096 );

		$stats = Util::getStatsFactory();
		$type = ElasticaErrorHandler::classifyError( $exception );
		$clusterName = $connection->getClusterName();
		$context['cirrussearch_error_type'] = $type;

		$stats->getCounter( "backend_failures_total" )
			->setLabel( "search_cluster", $clusterName )
			->setLabel( "type", $type )
			->increment();

		LoggerFactory::getInstance( 'CirrusSearch' )->warning(
			"Search backend error during {$logType} after {tookMs}: {error_message}",
			$context
		);
		return $status;
	}

	/**
	 * Get the search metrics we have
	 * @return array
	 */
	public function getSearchMetrics() {
		return $this->searchMetrics;
	}

	/**
	 * Log the completion of a request to Elasticsearch.
	 *
	 * @param Connection $connection
	 * @return RequestLog|null The log for the finished request, or null if no
	 * request was started.
	 */
	private function finishRequest( Connection $connection ) {
		if ( !$this->currentRequestLog ) {
			LoggerFactory::getInstance( 'CirrusSearch' )->warning(
				'finishRequest called without staring a request'
			);
			return null;
		}
		$log = $this->currentRequestLog;
		$this->currentRequestLog = null;

		$log->finish();
		$tookMs = $log->getTookMs();
		$clusterName = $connection->getClusterName();
		$this->searchMetrics['wgCirrusTookMs'] = $tookMs;
		self::$requestLogger->addRequest( $log, $this->user, $this->slowMillis );
		$type = $log->getQueryType();
		$stats = Util::getStatsFactory();
		$stats->getTiming( "request_time_seconds" )
			->setLabel( "search_cluster", $clusterName )
			->setLabel( "type", $type )
			->observe( $tookMs );
		if ( $log->getElasticTookMs() ) {
			$this->searchMetrics['wgCirrusElasticTime'] = $log->getElasticTookMs();
		}

		return $log;
	}

	/**
	 * @param string $key
	 * @param string $value
	 */
	public static function appendLastLogPayload( $key, $value ) {
		if ( self::$requestLogger !== null ) {
			// Guard only for unit tests that heavily mock classes
			self::$requestLogger->appendLastLogPayload( $key, $value );
		} else {
			Assert::invariant( defined( 'MW_PHPUNIT_TEST' ),
				'appendLastLogPayload must only be called after self::$requestLogger has been set ' .
				'or during unit tests' );
		}
	}

	/**
	 * @param string $description A psr-3 compliant string describing the request
	 * @param string $queryType The type of search being performed such as
	 * fulltext, get, etc.
	 * @param array $extra A map of additional request-specific data
	 * @return RequestLog
	 */
	protected function startNewLog( $description, $queryType, array $extra = [] ) {
		$log = $this->newLog( $description, $queryType, $extra );
		$this->start( $log );

		return $log;
	}

	/**
	 * @param string $description A psr-3 compliant string describing the request
	 * @param string $queryType The type of search being performed such as
	 * fulltext, get, etc.
	 * @param array $extra A map of additional request-specific data
	 * @return RequestLog
	 */
	abstract protected function newLog( $description, $queryType, array $extra = [] );

	/**
	 * @param string $searchType
	 * @return string search retrieval timeout
	 */
	protected function getTimeout( $searchType = 'default' ) {
		$timeout = $this->connection->getConfig()->getElement( 'CirrusSearchSearchShardTimeout', $searchType );
		if ( $timeout !== null ) {
			return $timeout;
		}
		$timeout = $this->connection->getConfig()->getElement( 'CirrusSearchSearchShardTimeout', 'default' );
		if ( $timeout !== null ) {
			return $timeout;
		}
		throw new ConfigException( "wgCirrusSearchSearchShardTimeout should have at least a 'default' entry configured" );
	}

	/**
	 * @param string $searchType
	 * @return int the client side timeout
	 */
	protected function getClientTimeout( $searchType = 'default' ) {
		$timeout = $this->connection->getConfig()->getElement( 'CirrusSearchClientSideSearchTimeout', $searchType );
		if ( $timeout !== null ) {
			return $timeout;
		}
		$timeout = $this->connection->getConfig()->getElement( 'CirrusSearchClientSideSearchTimeout', 'default' );
		if ( $timeout !== null ) {
			return $timeout;
		}
		throw new ConfigException( "wgCirrusSearchClientSideSearchTimeout should have at least a 'default' entry configured" );
	}

	protected function appendMetrics( SearchMetricsProvider $provider ) {
		$this->searchMetrics += $provider->getMetrics();
	}

	/**
	 * check validity of the multisearch response
	 *
	 * @param MultiResultSet $multiResultSet
	 * @return bool
	 */
	public static function isMSearchResultSetOK( MultiResultSet $multiResultSet ): bool {
		return !$multiResultSet->hasError() &&
			   // Catches HTTP errors (ex: 5xx) not reported
			   // by hasError()
			   $multiResultSet->getResponse()->isOk();
	}

	/**
	 * @param Search $search
	 * @param RequestLog $log
	 * @param Connection|null $connection
	 * @param callable|null $resultsTransformer that accepts a Multi/ResultSets
	 * @return Status
	 */
	protected function runMSearch(
		Search $search,
		RequestLog $log,
		?Connection $connection = null,
		?callable $resultsTransformer = null
	): Status {
		$connection = $connection ?: $this->connection;
		$this->start( $log );
		try {
			$multiResultSet = $search->search();
			$lastRequest = $connection->getClient()->getLastRequest();
			if ( !$multiResultSet->getResponse()->isOk() ) {
				// bad response from server. Should elastica be throwing an exception for this?
				if ( $lastRequest !== null ) {
					return $this->failure( new ResponseException( $lastRequest, $multiResultSet->getResponse() ), $connection );
				} else {
					return $this->failure( new RuntimeException( "Client::getLastRequest() should not be null" ), $connection );
				}
			}
			foreach ( $multiResultSet->getResultSets() as $resultSet ) {
				if ( $resultSet->getResponse()->hasError() ) {
					if ( $lastRequest !== null ) {
						return $this->failure( new ResponseException( $lastRequest, $resultSet->getResponse() ), $connection );
					} else {
						return $this->failure( new RuntimeException( "Client::getLastRequest() should not be null" ), $connection );
					}
				}
			}

			return $this->success( $resultsTransformer !== null ? $resultsTransformer( $multiResultSet ) : $multiResultSet, $connection );
		} catch ( ExceptionInterface $e ) {
			return $this->failure( $e, $connection );
		}
	}

	protected static function throwIfNotOk( Connection $connection, Response $response ) {
		if ( $response->isOK() ) {
			return;
		}
		$request = $connection->getClient()->getLastRequest();
		if ( $request == null ) {
			// I can't imagine how this would happen, but the type signature allows
			// for a null last request so we provide a minimal workaround.
			throw new \Elastica\Exception\RuntimeException(
				"Response reports failure, but no last request available" );
		}
		throw new ResponseException( $request, $response );
	}

}
