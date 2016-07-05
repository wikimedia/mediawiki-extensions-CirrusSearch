<?php

namespace CirrusSearch\Job;

use ArrayObject;
use CirrusSearch\Connection;
use CirrusSearch\Searcher;
use CirrusSearch\SearchConfig;
use CirrusSearch\Sanity\Checker;
use CirrusSearch\Sanity\QueueingRemediator;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use JobQueueGroup;
use Title;

/**
 * Job wrapper around Sanity\Checker
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
class CheckerJob extends Job {
	/**
	 * Construct a new CherckerJob.
	 * @param int $fromId
	 * @param int $toId
	 * @param int $delay
	 * @param string $profile sanitization profile to use
	 * @param string|null $cluster
	 * @return CheckerJob
	 */
	public static function build( $fromId, $toId, $delay, $profile, $cluster ) {
		$job = new self( Title::makeTitle( 0, "" ), array(
			'fromId' => $fromId,
			'toId' => $toId,
			'createdAt' => time(),
			'retryCount' => 0,
			'profile' => $profile,
			'cluster' => $cluster,
		) );
		$job->setDelay( $delay );
		return $job;
	}

	/**
	 * @return bool
	 * @throws \MWException
	 */
	protected function doJob() {
		$config = MediaWikiServices::getInstance()
			->getConfigFactory()
			->makeConfig( 'CirrusSearch' );
		return $this->runCheck( $config );
	}

	/**
	 * @param SearchConfig $config
	 * @return bool
	 */
	private function runCheck( SearchConfig $config ) {
		$profile = $config->getElement( 'CirrusSearchSanitizationProfiles', $this->params['profile'] );
		if( !$profile ) {
			LoggerFactory::getInstance( 'CirrusSearch' )->warning(
				"Cannot run CheckerJob invalid profile {profile} provided, check CirrusSearchSanityCheck config.",
				array(
					'profile' => $this->params['profile']
				)
			);
			return false;
		}
		$maxPressure = isset( $profile['update_jobs_max_pressure'] ) ? $profile['update_jobs_max_pressure'] : null;
		if ( !$maxPressure || $maxPressure < 0 ) {
			LoggerFactory::getInstance( 'CirrusSearch' )->warning(
				"Cannot run CheckerJob invalid update_jobs_max_pressure, check CirrusSearchSanityCheck config."
			);
			return false;
		}
		$batchSize = isset( $profile['checker_batch_size'] ) ? $profile['checker_batch_size'] : null;
		if ( !$batchSize || $batchSize < 0 ) {
			LoggerFactory::getInstance( 'CirrusSearch' )->warning(
				"Cannot run CheckerJob invalid checker_batch_size, check CirrusSearchSanityCheck config."
			);
			return false;
		}

		$maxTime = isset( $profile['checker_job_max_time'] ) ? $profile['checker_job_max_time'] : null;
		if ( !$maxTime || $maxTime < 0 ) {
			LoggerFactory::getInstance( 'CirrusSearch' )->warning(
				"Cannot run CheckerJob invalid checker_job_max_time, check CirrusSearchSanityCheck config."
			);
			return false;
		}

		$startTime = time();

		$connections = $this->decideClusters( $config );
		$clusterNames = implode( ', ', array_keys( $connections ) );
		LoggerFactory::getInstance( 'CirrusSearch' )->debug(
			"Running CheckerJob on cluster $clusterNames {diff}s after insertion",
			array(
				'diff' => time() - $this->params['createdAt'],
				'clusters' => array_keys( $connections ),
			)
		);

		$from = $this->params['fromId'];
		$to = $this->params['toId'];

		$pageCache = new ArrayObject();
		$checkers = array();
		foreach( $connections as $cluster => $connection ) {
			$searcher = new Searcher( $connection, 0, 0, $config, array(), null );
			$checker = new Checker(
				$connection,
				new QueueingRemediator( $cluster ),
				$searcher,
				false, // logSane
				false, // fastRedirectCheck
				$pageCache
			);
			$checkers[] = $checker;
		}

		$ranges = array_chunk( range( $from, $to ), $batchSize );
		while ( $pageIds = array_shift( $ranges ) ) {
			if ( self::getPressure() > $maxPressure ) {
				$this->retry( "too much pressure on update jobs", reset( $pageIds ) );
				return false;
			}
			if ( time() - $startTime > $maxTime ) {
				$this->retry( "execution time exceeded checker_job_max_time", reset( $pageIds ) );
				return false;
			}
			$pageCache->exchangeArray( array() );
			foreach( $checkers as $checker ) {
				$checker->check( $pageIds );
			}
		}
		return true;
	}

	/**
	 * @return int the total number of update jobs enqueued
	 */
	public static function getPressure() {
		$queues = array(
			'cirrusSearchLinksUpdatePrioritized',
			'cirrusSearchLinksUpdate',
			'cirrusSearchElasticaWrite',
			'cirrusSearchOtherIndex',
			'cirrusSearchDeletePages',
		);
		$size = 0;
		foreach( $queues as $queueName ) {
			$queue = JobQueueGroup::singleton()->get( $queueName );
			$size += $queue->getSize();
			$size += $queue->getDelayedCount();
		}

		return $size;
	}

	/**
	 * This job handles all its own retries internally.
	 */
	public function allowRetries() {
		return false;
	}

	/**
	 * Retry the job later with a new from offset
	 * @param string $cause why we retry
	 * @param int $newFrom the new from offset
	 */
	private function retry( $cause, $newFrom ) {
		$delay = self::backoffDelay( $this->params['retryCount'] );
		$job = clone $this;
		$job->params['retryCount']++;
		$job->params['fromId'] = $newFrom;
		$job->setDelay( $delay );
		LoggerFactory::getInstance( 'CirrusSearch' )->info(
			"Sanitize CheckerJob: $cause, Requeueing CheckerJob with a delay of {delay}s.",
			array(
				'delay' => $delay
			)
		);
		JobQueueGroup::singleton()->push( $job );
	}
}
