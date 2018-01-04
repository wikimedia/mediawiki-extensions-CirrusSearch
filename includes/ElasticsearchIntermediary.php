<?php

namespace CirrusSearch;

use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
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
abstract class ElasticsearchIntermediary {
	/**
	 * @var Connection
	 */
	protected $connection;

	/**
	 * @var User|null user for which we're performing this search or null in
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
	 * @param User|null $user user for which this search is being performed.
	 *  Attached to slow request logs.  Note that null isn't for anonymous users
	 *  - those are still User objects and should be provided if possible.  Null
	 *  is for when the action is being performed in some context where the user
	 *  that caused it isn't available.  Like when an action is being performed
	 *  during a job.
	 * @param float $slowSeconds how many seconds a request through this
	 *  intermediary needs to take before it counts as slow.  0 means none count
	 *  as slow.
	 * @param int $extraBackendLatency artificial backend latency.
	 */
	protected function __construct( Connection $connection, User $user = null, $slowSeconds, $extraBackendLatency = 0 ) {
		$this->connection = $connection;
		if ( is_null( $user ) ) {
			$user = RequestContext::getMain()->getUser();
		}
		$this->user = $user;
		$this->slowMillis = (int)( 1000 * $slowSeconds );
		$this->extraBackendLatency = $extraBackendLatency;
		if ( self::$requestLogger === null ) {
			self::$requestLogger = new RequestLogger;
		}
		// This isn't explicitly used, but we need to make sure it is
		// instantiated so it has the opportunity to override global
		// configuration for test buckets.
		UserTesting::getInstance();
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
	 * @param array[Search\ResultSet|null] $matches
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
	 * Mark the start of a request to Elasticsearch.  Public so it can be
	 * called from pool counter methods.
	 *
	 * @param RequestLog $log
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
	 * @param mixed $result result of the request.  defaults to null in case
	 *  the request doesn't have a result
	 * @return Status wrapping $result
	 */
	public function success( $result = null ) {
		$this->finishRequest();
		return Status::newGood( $result );
	}

	/**
	 * Log a successful request when the response comes from a cache outside
	 * elasticsearch. This is a combination of self::start() and self::success().
	 *
	 * @param RequestLog $log
	 */
	public function successViaCache( RequestLog $log ) {
		if ( $this->extraBackendLatency ) {
			usleep( $this->extraBackendLatency );
		}
		self::$requestLogger->addRequest( $log );
	}

	public function multiFailure( \Elastica\Multi\ResultSet $multiResultSet ) {
		$status = $multiResultSet->getResponse()->getStatus();
		if ( $status < 200 || $status >= 300 ) {
			// bad response from server. Should elastica be throwing an exception for this?
			return $this->failure( new \Elastica\Exception\ResponseException(
				$this->connection->getClient()->getLastRequest(),
				$multiResultSet->getResponse()
			) );
		}
		foreach ( $multiResultSet->getResultSets() as $resultSet ) {
			if ( $resultSet->getResponse()->hasError() ) {
				return $this->failure( new \Elastica\Exception\ResponseException(
					$this->connection->getClient()->getLastRequest(),
					$resultSet->getResponse()
				) );
			}
		}

		// Should never get here
		return $this->success( $multiResultSet );
	}

	/**
	 * Log a failure and return an appropriate status.  Public so it can be
	 * called from pool counter methods.
	 *
	 * @param \Elastica\Exception\ExceptionInterface|null $exception if the request failed
	 * @return Status representing a backend failure
	 */
	public function failure( \Elastica\Exception\ExceptionInterface $exception = null ) {
		$log = $this->finishRequest();
		$context = $log->getLogVariables();
		list( $status, $message ) = ElasticaErrorHandler::extractMessageAndStatus( $exception );
		$context['error_message'] = $message;

		$stats = MediaWikiServices::getInstance()->getStatsdDataFactory();
		$type = ElasticaErrorHandler::classifyError( $exception );
		$clusterName = $this->connection->getClusterName();
		$stats->increment( "CirrusSearch.$clusterName.backend_failure.$type" );

		LoggerFactory::getInstance( 'CirrusSearch' )->warning(
			"Search backend error during {$log->getDescription()} after {tookMs}: {error_message}",
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
	 * @return RequestLog|null The log for the finished request, or null if no
	 * request was started.
	 */
	private function finishRequest() {
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
		$clusterName = $this->connection->getClusterName();
		$stats = MediaWikiServices::getInstance()->getStatsdDataFactory();
		$stats->timing( "CirrusSearch.$clusterName.requestTime", $tookMs );
		$this->searchMetrics['wgCirrusTookMs'] = $tookMs;
		self::$requestLogger->addRequest( $log, $this->user, $this->slowMillis );
		$type = $log->getQueryType();
		$stats->timing( "CirrusSearch.$clusterName.requestTimeMs.$type", $tookMs );
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
		self::$requestLogger->appendLastLogPayload( $key, $value );
	}

	/**
	 * @param string $description A psr-3 compliant string describing the request
	 * @param string $queryType The type of search being performed such as
	 * fulltext, get, etc.
	 * @param string[] $extra A map of additional request-specific data
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
	 * @param string[] $extra A map of additional request-specific data
	 * @return RequestLog
	 */
	abstract protected function newLog( $description, $queryType, array $extra = [] );
}
