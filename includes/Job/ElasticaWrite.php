<?php

namespace CirrusSearch\Job;

use CirrusSearch\ClusterSettings;
use CirrusSearch\Connection;
use CirrusSearch\DataSender;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use Status;
use Wikimedia\Assert\Assert;

/**
 * Performs writes to elasticsearch indexes with requeuing and an
 * exponential backoff (if supported by jobqueue) when the index
 * writes fail.
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
class ElasticaWrite extends CirrusGenericJob {
	private const MAX_ERROR_RETRY = 4;

	/**
	 * @var array Map from method name to list of classes to
	 *  handle serialization for each argument.
	 */
	private static $SERDE = [
		'sendData' => [ null, ElasticaDocumentsJsonSerde::class ],
	];

	/**
	 * @param ClusterSettings $cluster
	 * @param string $method
	 * @param array $arguments
	 * @param array $params
	 * @return ElasticaWrite
	 */
	public static function build( ClusterSettings $cluster, string $method, array $arguments, array $params = [] ) {
		return new self( [
			'method' => $method,
			'arguments' => self::serde( $method, $arguments ),
			'cluster' => $cluster->getName(),
			// This does not directly partition the jobs, it only provides a value
			// to use during partitioning. The job queue must be separately
			// configured to utilize this value.
			'jobqueue_partition' => self::partitioningKey( $cluster ),
		] + $params );
	}

	/**
	 * Generate a cluster specific partitioning key
	 *
	 * Some job queue implementations, such as cpjobqueue, can partition the
	 * execution of jobs based on a parameter of the job. By default we
	 * provide one partition per cluster, but allow to configure multiple
	 * partitions per cluster if more throughput is necessary. Within a
	 * single cluster jobs are distributed randomly.
	 *
	 * @param ClusterSettings $settings
	 * @return string A value suitable for partitioning jobs per-cluster
	 */
	private static function partitioningKey( ClusterSettings $settings ): string {
		$numPartitions = $settings->getElasticaWritePartitionCount();
		$partition = mt_rand() % $numPartitions;
		return "{$settings->getName()}-{$partition}";
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
	 * @param array $params
	 */
	public function __construct( array $params ) {
		parent::__construct( $params + [
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
		// While we can only have a single connection per job, we still
		// use decideClusters() which includes a variety of safeguards.
		$connections = $this->decideClusters();
		if ( empty( $connections ) ) {
			// Chosen cluster no longer exists in configuration.
			return true;
		}
		Assert::precondition( count( $connections ) == 1,
			'per self::build() we must have a single connection' );

		$conn = reset( $connections );
		$arguments = self::serde( $this->params['method'], $this->params['arguments'], false );

		LoggerFactory::getInstance( 'CirrusSearch' )->debug(
			"Running {method} on cluster {cluster} {diff}s after insertion",
			[
				'method' => $this->params['method'],
				'arguments' => $arguments,
				'diff' => time() - $this->params['createdAt'],
				'cluster' => $conn->getClusterName(),
			]
		);

		$retry = [];
		$error = [];
		$sender = new DataSender( $conn, $this->searchConfig );
		try {
			$status = $sender->{$this->params['method']}( ...$arguments );
		} catch ( \Exception $e ) {
			LoggerFactory::getInstance( 'CirrusSearch' )->warning(
				"Exception thrown while running DataSender::{method} in cluster {cluster}: {errorMessage}",
				[
					'method' => $this->params['method'],
					'cluster' => $conn->getClusterName(),
					'errorMessage' => $e->getMessage(),
					'exception' => $e,
				]
			);
			$status = Status::newFatal( 'cirrussearch-send-failure' );
		}

		$ok = true;
		if ( !$status->isOK() ) {
			$action = $this->requeueError( $conn ) ? "Requeued" : "Dropped";
			$this->setLastError( "ElasticaWrite job failed: {$action}" );
			$ok = false;
		}

		return $ok;
	}

	/**
	 * Re-queue job that failed, or drop the job if it has failed
	 * too many times
	 *
	 * @param Connection $conn
	 * @return bool True when the job has been queued
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
			return false;
		} else {
			$delay = $this->backoffDelay( $this->params['retryCount'] );
			$params = $this->params;
			$params['errorCount']++;
			unset( $params['jobReleaseTimestamp'] );
			$params += self::buildJobDelayOptions( self::class, $delay );
			$job = new self( $params );
			// Individual failures should have already logged specific errors,
			LoggerFactory::getInstance( 'CirrusSearch' )->info(
				"ElasticaWrite job reported failure on cluster {cluster}. Requeueing job with delay of {delay}.",
				[
					'cluster' => $conn->getClusterName(),
					'delay' => $delay
				]
			);
			MediaWikiServices::getInstance()->getJobQueueGroup()->push( $job );
			return true;
		}
	}
}
