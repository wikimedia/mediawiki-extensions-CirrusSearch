<?php

namespace CirrusSearch\Job;

use ArrayObject;
use CirrusSearch\Profile\SearchProfileService;
use CirrusSearch\Searcher;
use CirrusSearch\Sanity\Checker;
use CirrusSearch\Sanity\QueueingRemediator;
use MediaWiki\Logger\LoggerFactory;
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
	 * @const int max number of retries, 3 means that the job can be run at
	 * most 4 times.
	 */
	const JOB_MAX_RETRIES = 3;

	/**
	 * Construct a new CherckerJob.
	 * @param int $fromPageId
	 * @param int $toPageId
	 * @param int $delay
	 * @param string $profile sanitization profile to use
	 * @param string|null $cluster
	 * @return CheckerJob
	 */
	public static function build( $fromPageId, $toPageId, $delay, $profile, $cluster ) {
		$job = new self( Title::makeTitle( NS_SPECIAL, "Badtitle/" . __CLASS__ ), [
			'fromPageId' => $fromPageId,
			'toPageId' => $toPageId,
			'createdAt' => time(),
			'retryCount' => 0,
			'profile' => $profile,
			'cluster' => $cluster,
		] );
		$job->setDelay( $delay );
		return $job;
	}

	/**
	 * @param Title $title
	 * @param array $params
	 */
	public function __construct( $title, $params ) {
		// BC for jobs created before id fields were clarified to be explicitly page id's
		if ( isset( $params['fromId'] ) ) {
			$params['fromPageId'] = $params['fromId'];
			unset( $params['fromId'] );
		}
		if ( isset( $params['toId'] ) ) {
			$params['toPageId'] = $params['toId'];
			unset( $params['toId'] );
		}
		parent::__construct( $title, $params );
	}

	/**
	 * @return bool
	 * @throws \MWException
	 */
	protected function doJob() {
		$profile = $this->searchConfig
			->getProfileService()
			->loadProfileByName( SearchProfileService::SANEITIZER, $this->params['profile'], false );
		if ( !$profile ) {
			LoggerFactory::getInstance( 'CirrusSearch' )->warning(
				"Cannot run CheckerJob invalid profile {profile} provided, check CirrusSearchSanityCheck config.",
				[
					'profile' => $this->params['profile']
				]
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

		$chunkSize = isset( $profile['jobs_chunk_size'] ) ? $profile['jobs_chunk_size'] : null;
		if ( !$chunkSize || $chunkSize < 0 ) {
			LoggerFactory::getInstance( 'CirrusSearch' )->warning(
				"Cannot run CheckerJob invalid jobs_chunk_size, check CirrusSearchSanityCheck config."
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

		$from = $this->params['fromPageId'];
		$to = $this->params['toPageId'];

		if ( $from > $to ) {
			LoggerFactory::getInstance( 'CirrusSearch' )->warning(
				"Cannot run CheckerJob: from > to ( {from} > {to} ), job is corrupted?",
				[
					'from' => $from,
					'to' => $to,
				]
			);
			return false;
		}

		if ( ( $to - $from ) > $chunkSize ) {
			LoggerFactory::getInstance( 'CirrusSearch' )->warning(
				"Cannot run CheckerJob: to - from > chunkSize( {from}, {to} > {chunkSize} ), job is corrupted or profile mismatch?",
				[
					'from' => $from,
					'to' => $to,
					'chunkSize' => $chunkSize,
				]
			);
			return false;
		}

		$connections = $this->decideClusters();
		$clusterNames = implode( ', ', array_keys( $connections ) );

		LoggerFactory::getInstance( 'CirrusSearch' )->debug(
			"Running CheckerJob on cluster $clusterNames {diff}s after insertion",
			[
				'diff' => time() - $this->params['createdAt'],
				'clusters' => array_keys( $connections ),
			]
		);

		$startTime = time();

		$pageCache = new ArrayObject();
		$checkers = [];
		foreach ( $connections as $cluster => $connection ) {
			$searcher = new Searcher( $connection, 0, 0, $this->searchConfig, [], null );
			$checker = new Checker(
				$this->searchConfig,
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
				return true;
			}
			if ( time() - $startTime > $maxTime ) {
				$this->retry( "execution time exceeded checker_job_max_time", reset( $pageIds ) );
				return true;
			}
			$pageCache->exchangeArray( [] );
			foreach ( $checkers as $checker ) {
				$checker->check( $pageIds );
			}
		}
		return true;
	}

	/**
	 * @return int the total number of update jobs enqueued
	 */
	public static function getPressure() {
		$queues = [
			'cirrusSearchLinksUpdatePrioritized',
			'cirrusSearchLinksUpdate',
			'cirrusSearchElasticaWrite',
			'cirrusSearchOtherIndex',
			'cirrusSearchDeletePages',
		];
		$size = 0;
		foreach ( $queues as $queueName ) {
			$queue = JobQueueGroup::singleton()->get( $queueName );
			$size += $queue->getSize();
			$size += $queue->getDelayedCount();
		}

		return $size;
	}

	/**
	 * This job handles all its own retries internally.
	 * @return bool
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
		if ( $this->params['retryCount'] >= self::JOB_MAX_RETRIES ) {
			LoggerFactory::getInstance( 'CirrusSearch' )->info(
				"Sanitize CheckerJob: $cause ({fromPageId}:{toPageId}), Abandonning CheckerJob after {retries} retries, (jobs_chunk_size too high?).",
				[
					'retries' => $this->params['retryCount'],
					'fromPageId' => $this->params['fromPageId'],
					'toPageId' => $this->params['toPageId'],
				]
			);
			return;
		}

		$delay = self::backoffDelay( $this->params['retryCount'] );
		$job = clone $this;
		$job->params['retryCount']++;
		$job->params['fromPageId'] = $newFrom;
		$job->setDelay( $delay );
		LoggerFactory::getInstance( 'CirrusSearch' )->info(
			"Sanitize CheckerJob: $cause ({fromPageId}:{toPageId}), Requeueing CheckerJob with a delay of {delay}s.",
			[
				'delay' => $delay,
				'fromPageId' => $job->params['fromPageId'],
				'toPageId' => $job->params['toPageId'],
			]
		);
		JobQueueGroup::singleton()->push( $job );
	}
}
