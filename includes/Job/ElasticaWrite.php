<?php

namespace CirrusSearch\Job;

use CirrusSearch\Connection;
use CirrusSearch\DataSender;
use ConfigFactory;
use JobQueueGroup;
use MediaWiki\Logger\LoggerFactory;
use Status;

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
	/**
	 * @param Title $title A mediawiki title related to the job
	 * @param array $params
	 */
	public function __construct( $title, $params ) {
		parent::__construct( $title, $params + array(
			'createdAt' => time(),
			'errorCount' => 0,
			'cluster' => null,
		) );
	}

	protected function decideClusters() {
		$config = ConfigFactory::getDefaultInstance()->makeConfig( 'CirrusSearch' );
		if ( $this->params['cluster'] !== null &&
				$config->getElement( 'CirrusSearchClusters', $this->params['cluster'] ) === null ) {
			// Just in case a job is present in the queue but its cluster
			// has been removed from the config file.
			$cluster = $this->params['cluster'];
			LoggerFactory::getInstance( 'CirrusSearch' )->warning(
				"Received job {method} for unknown cluster {cluster} {diff}s after insertion",
				array(
					'method' => $this->params['method'],
					'arguments' => $this->params['arguments'],
					'diff' => time() - $this->params['createdAt'],
					'cluster' =>  $cluster
				)
			);
			$this->setAllowRetries( false );
			throw new \RuntimeException( "Received job for unknown cluster $cluster." );
		}
		if ( $this->params['cluster'] !== null ) {
			// parent::__construct initialized the correct connection
			$name = $this->connection->getClusterName();
			return array( $name => $this->connection );
		}

		$clusters = $config->get( 'CirrusSearchClusters' );
		$connections = array();
		foreach ( array_keys( $clusters ) as $name ) {
			$connections[$name] = Connection::getPool( $config, $name );
		}
		return $connections;
	}

	protected function doJob() {

		$connections = $this->decideClusters();
		$clusterNames = implode( ', ', array_keys( $connections ) );
		LoggerFactory::getInstance( 'CirrusSearch' )->debug(
			"Running {method} on cluster $clusterNames {diff}s after insertion",
			array(
				'method' => $this->params['method'],
				'arguments' => $this->params['arguments'],
				'diff' => time() - $this->params['createdAt'],
				'clusters' => array_keys( $connections ),
			)
		);
		foreach ( $connections as $clusterName => $conn ) {
			if ( $this->params['clientSideTimeout'] ) {
				$conn->setTimeout( $this->params['clientSideTimeout'] );
			}

			$sender = new DataSender( $conn );
			try {
				$status = call_user_func_array(
					array( $sender, $this->params['method'] ),
					$this->params['arguments']
				);
			} catch ( \Exception $e ) {
				LoggerFactory::getInstance( 'CirrusSearch' )->warning(
					"Exception thrown while running DataSender::{method} in cluster {cluster}",
					array(
						'method' => $this->params['method'],
						'cluster' => $clusterName,
						'exception' => $e,
					)
				);
				$status = Status::newFatal( 'cirrussearch-send-failure' );
			}

			if ( $status->hasMessage( 'cirrussearch-indexes-frozen' ) ) {
				$diff = time() - $this->params['createdAt'];
				$dropTimeout = $conn->getSettings()->getDropDelayedJobsAfter();
				if ( $diff > $dropTimeout ) {
					LoggerFactory::getInstance( 'CirrusSearchChangeFailed' )->warning(
						"Dropping delayed job for DataSender::{method} in cluster {cluster} after waiting {diff}s",
						array(
							'method' => $this->params['method'],
							'cluster' => $clusterName,
							'diff' => $diff,
						));
				} else {
					$delay = self::backoffDelay( $this->params['errorCount'] );
					LoggerFactory::getInstance( 'CirrusSearch' )->debug(
						"Requeueing job with frozen indexes to be run {$delay}s later");

					$job = clone $this;
					$job->params['errorCount']++;
					$job->params['cluster'] = $clusterName;
					$job->setDelay( $delay );
					JobQueueGroup::singleton()->push( $job );
				}

			} elseif ( !$status->isOK() ) {
				// Individual failures should have already logged specific errors,
				if ( count( $connections ) === 1 ) {
					// returning false here will requeue the job to be run at a later time.
					LoggerFactory::getInstance( 'CirrusSearch' )->info(
						"Job reported failure on cluster {cluster}, allowing job queue to requeue",
						array( 'cluster' => $clusterName ) );
					return false;
				} else {
					// with multiple connections we only want to re-queue the
					// failed cluster. This does mean these jobs get one more
					// attempt than usual, as this one doesn't count towards
					// the threshold.
					LoggerFactory::getInstance( 'CirrusSearch' )->info(
						"Job reported failure on cluster {cluster}. Queueing single cluster job.",
						array( 'cluster' => $clusterName ) );
					$job = clone $this;
					$job->params['cluster'] = $clusterName;
					JobQueueGroup::singleton()->push( $job );
				}
			}
		}

		return true;
	}

	/**
	 * @param int $errorCount The number of times the job has errored out.
	 * @return int Number of seconds to delay. With the default minimum exponent
	 *  of 6 the possible return values are  64, 128, 256, 512 and 1024 giving a
	 *  maximum delay of 17 minutes.
	 */
	public static function backoffDelay( $errorCount ) {
		global $wgCirrusSearchWriteBackoffExponent;
		return ceil( pow( 2, $wgCirrusSearchWriteBackoffExponent + rand(0, min( $errorCount, 4 ) ) ) );
	}
}
