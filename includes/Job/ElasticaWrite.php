<?php

namespace CirrusSearch\Job;

use CirrusSearch\Connection;
use CirrusSearch\DataSender;
use JobQueueGroup;
use MediaWiki\Logger\LoggerFactory;
use Status;
use Title;

/**
 * Performs writes to elasticsearch indexes with requeuing and an
 * exponential backoff when the indexes being written to are frozen.
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
class ElasticaWrite extends Job {
	const MAX_ERROR_RETRY = 4;

	/**
	 * @var array Map from method name to list of classes to
	 *  handle serialization for each argument.
	 */
	private static $SERDE = [
		'sendData' => [ null, ElasticaDocumentsJsonSerde::class ],
	];

	public static function build( Title $title, $method, array $arguments, array $params ) {
		return new self( $title, [
			'method' => $method,
			'arguments' => self::serde( $method, $arguments ),
			'serialized' => true,
		] + $params );
	}

	private static function serde( $method, array $arguments, $serialize = true ) {
		if ( isset( self::$SERDE[$method] ) ) {
			foreach ( self::$SERDE[$method] as $i => $serde ) {
				if ( $serde !== null && array_key_exists( $i, $arguments ) ) {
					$impl = new $serde();
					if ( $serialize ) {
						$arguments[$i] = $impl->serialize( $arguments[$i] );
					} else {
						$arguments[$i] = $impl->deserialize( $arguments[$i] );
					}
				}
			}
		}
		return $arguments;
	}

	/**
	 * Entry point for jobs received from the job queue. Creating new
	 * jobs should be done via self::build.
	 *
	 * @param Title $title A mediawiki title related to the job
	 * @param array $params
	 */
	public function __construct( $title, $params ) {
		parent::__construct( $title, $params + [
			'createdAt' => time(),
			'errorCount' => 0,
			'retryCount' => 0,
			'cluster' => null,
		] );
	}

	/**
	 * This job handles all its own retries internally. These jobs are so
	 * numerous that if they were to start failing they would possibly
	 * overflow the job queue and bring down redis in production.
	 *
	 * Basically we just can't let these jobs hang out in the abandoned
	 * queue for a week like retries typically do. If these jobs get
	 * failed they will log to CirrusSearchChangeFailed which is a signal
	 * that some point in time around the failure needs to be reindexed
	 * manually. See https://wikitech.wikimedia.org/wiki/Search for more
	 * details.
	 * @return bool
	 */
	public function allowRetries() {
		return false;
	}

	/**
	 * @return bool
	 */
	protected function doJob() {
		$connections = $this->decideClusters();
		$clusterNames = implode( ', ', array_keys( $connections ) );

		$arguments = $this->params['arguments'];
		if ( !empty( $this->params['serialized'] ) ) {
			$arguments = $this->serde( $this->params['method'], $arguments, false );
		}

		LoggerFactory::getInstance( 'CirrusSearch' )->debug(
			"Running {method} on cluster $clusterNames {diff}s after insertion",
			[
				'method' => $this->params['method'],
				'arguments' => $arguments,
				'diff' => time() - $this->params['createdAt'],
				'clusters' => array_keys( $connections ),
			]
		);

		$retry = [];
		$error = [];
		foreach ( $connections as $clusterName => $conn ) {
			$sender = new DataSender( $conn, $this->searchConfig );
			try {
				$status = call_user_func_array(
					[ $sender, $this->params['method'] ],
					$arguments
				);
			} catch ( \Exception $e ) {
				LoggerFactory::getInstance( 'CirrusSearch' )->warning(
					"Exception thrown while running DataSender::{method} in cluster {cluster}: {errorMessage}",
					[
						'method' => $this->params['method'],
						'cluster' => $clusterName,
						'errorMessage' => $e->getMessage(),
						'exception' => $e,
					]
				);
				$status = Status::newFatal( 'cirrussearch-send-failure' );
			}

			if ( $status->hasMessage( 'cirrussearch-indexes-frozen' ) ) {
				$retry[] = $conn;
			} elseif ( !$status->isOK() ) {
				$error[] = $conn;
			}
		}

		if ( empty( $this->params['doNotRetry'] ) ) {
			foreach ( $retry as $conn ) {
				$this->requeueRetry( $conn );
			}
			foreach ( $error as $conn ) {
				$this->requeueError( $conn );
			}
		}
		if ( !empty( $error ) ) {
			$this->setLastError( "ElasticaWrite job reported " . count( $error ) . " failure(s) and " . count( $retry ) . " frozen." );
			return false;
		}

		return true;
	}

	/**
	 * Re-queue job that is frozen, or drop the job if it has
	 * been frozen for too long.
	 *
	 * @param Connection $conn
	 */
	private function requeueRetry( Connection $conn ) {
		$diff = time() - $this->params['createdAt'];
		$dropTimeout = $conn->getSettings()->getDropDelayedJobsAfter();
		if ( $diff > $dropTimeout ) {
			LoggerFactory::getInstance( 'CirrusSearchChangeFailed' )->warning(
				"Dropping delayed ElasticaWrite job for DataSender::{method} in cluster {cluster} after waiting {diff}s",
				[
					'method' => $this->params['method'],
					'cluster' => $conn->getClusterName(),
					'diff' => $diff,
				]
			);
		} else {
			$delay = self::backoffDelay( $this->params['retryCount'] );
			$job = clone $this;
			$job->params['retryCount']++;
			$job->params['cluster'] = $conn->getClusterName();
			$job->setDelay( $delay );
			LoggerFactory::getInstance( 'CirrusSearch' )->debug(
				"ElasticaWrite job reported frozen on cluster {cluster}. Requeueing job with delay of {delay}s",
				[
					'cluster' => $conn->getClusterName(),
					'delay' => $delay
				]
			);
			JobQueueGroup::singleton()->push( $job );
		}
	}

	/**
	 * Re-queue job that failed, or drop the job if it has failed
	 * too many times
	 *
	 * @param Connection $conn
	 */
	private function requeueError( Connection $conn ) {
		if ( $this->params['errorCount'] >= self::MAX_ERROR_RETRY ) {
			LoggerFactory::getInstance( 'CirrusSearchChangeFailed' )->warning(
				"Dropping failing ElasticaWrite job for DataSender::{method} in cluster {cluster} after repeated failure",
				[
					'method' => $this->params['method'],
					'cluster' => $conn->getClusterName(),
				]
			);
		} else {
			$delay = self::backoffDelay( $this->params['errorCount'] );
			$job = clone $this;
			$job->params['errorCount']++;
			$job->params['cluster'] = $conn->getClusterName();
			$job->setDelay( $delay );
			// Individual failures should have already logged specific errors,
			LoggerFactory::getInstance( 'CirrusSearch' )->info(
				"ElasticaWrite job reported failure on cluster {cluster}. Requeueing job with delay of {delay}.",
				[
					'cluster' => $conn->getClusterName(),
					'delay' => $delay
				]
			);
			JobQueueGroup::singleton()->push( $job );
		}
	}
}
