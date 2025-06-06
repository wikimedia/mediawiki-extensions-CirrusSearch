<?php

namespace CirrusSearch\Job;

use ArrayObject;
use CirrusSearch\Profile\SearchProfileService;
use CirrusSearch\Sanity\AllClustersQueueingRemediator;
use CirrusSearch\Sanity\BufferedRemediator;
use CirrusSearch\Sanity\Checker;
use CirrusSearch\Sanity\CheckerException;
use CirrusSearch\Sanity\LogOnlyRemediator;
use CirrusSearch\Sanity\MultiClusterRemediatorHelper;
use CirrusSearch\Sanity\QueueingRemediator;
use CirrusSearch\Searcher;
use CirrusSearch\UpdateGroup;
use CirrusSearch\Util;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

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
class CheckerJob extends CirrusGenericJob {
	/**
	 * @const int max number of retries, 3 means that the job can be run at
	 * most 4 times.
	 */
	private const JOB_MAX_RETRIES = 3;

	/**
	 * Construct a new CherckerJob.
	 * @param int $fromPageId
	 * @param int $toPageId
	 * @param int $delay
	 * @param string $profile sanitization profile to use
	 * @param string|null $cluster
	 * @param int $loopId The number of times the checker jobs have looped
	 *  over the pages to be checked.
	 * @param JobQueueGroup $jobQueueGroup
	 * @return self
	 */
	public static function build( $fromPageId, $toPageId, $delay, $profile, $cluster, $loopId, JobQueueGroup $jobQueueGroup ): self {
		$job = new self( [
			'fromPageId' => $fromPageId,
			'toPageId' => $toPageId,
			'createdAt' => time(),
			'retryCount' => 0,
			'profile' => $profile,
			'cluster' => $cluster,
			'loopId' => $loopId,
		] + self::buildJobDelayOptions( self::class, $delay, $jobQueueGroup ) );
		return $job;
	}

	public function __construct( array $params ) {
		// BC for jobs created before id fields were clarified to be explicitly page id's
		if ( isset( $params['fromId'] ) ) {
			$params['fromPageId'] = $params['fromId'];
			unset( $params['fromId'] );
		}
		if ( isset( $params['toId'] ) ) {
			$params['toPageId'] = $params['toId'];
			unset( $params['toId'] );
		}
		// BC for jobs created before loop id existed
		if ( !isset( $params['loopId'] ) ) {
			$params['loopId'] = 0;
		}
		parent::__construct( $params );
	}

	/**
	 * @return bool
	 */
	protected function doJob() {
		$profile = $this->searchConfig
			->getProfileService()
			->loadProfileByName( SearchProfileService::SANEITIZER, $this->params['profile'], false );

		// First perform a set of sanity checks and return true to fake a success (to prevent retries)
		// in case the job params are incorrect. These errors are generally unrecoverable.
		if ( !$profile ) {
			LoggerFactory::getInstance( 'CirrusSearch' )->warning(
				"Cannot run CheckerJob invalid profile {profile} provided, check CirrusSearchSanityCheck config.",
				[
					'profile' => $this->params['profile']
				]
			);
			return true;
		}
		$maxPressure = $profile['update_jobs_max_pressure'] ?? null;
		if ( !$maxPressure || $maxPressure < 0 ) {
			LoggerFactory::getInstance( 'CirrusSearch' )->warning(
				"Cannot run CheckerJob invalid update_jobs_max_pressure, check CirrusSearchSanityCheck config."
			);
			return true;
		}
		$batchSize = $profile['checker_batch_size'] ?? null;
		if ( !$batchSize || $batchSize < 0 ) {
			LoggerFactory::getInstance( 'CirrusSearch' )->warning(
				"Cannot run CheckerJob invalid checker_batch_size, check CirrusSearchSanityCheck config."
			);
			return true;
		}

		$chunkSize = $profile['jobs_chunk_size'] ?? null;
		if ( !$chunkSize || $chunkSize < 0 ) {
			LoggerFactory::getInstance( 'CirrusSearch' )->warning(
				"Cannot run CheckerJob invalid jobs_chunk_size, check CirrusSearchSanityCheck config."
			);
			return true;
		}

		$maxTime = $profile['checker_job_max_time'] ?? null;
		if ( !$maxTime || $maxTime < 0 ) {
			LoggerFactory::getInstance( 'CirrusSearch' )->warning(
				"Cannot run CheckerJob invalid checker_job_max_time, check CirrusSearchSanityCheck config."
			);
			return true;
		}

		$connections = $this->decideClusters( UpdateGroup::CHECK_SANITY );
		if ( !$connections ) {
			return true;
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
			return true;
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
			return true;
		}

		$clusterNames = implode( ', ', array_keys( $connections ) );

		LoggerFactory::getInstance( 'CirrusSearch' )->debug(
			"Running CheckerJob on cluster $clusterNames {diff}s after insertion",
			[
				'diff' => time() - $this->params['createdAt'],
				'clusters' => array_keys( $connections ),
			]
		);

		$isOld = null;
		$reindexAfterLoops = $profile['reindex_after_loops'] ?? null;
		if ( $reindexAfterLoops ) {
			$isOld = Checker::makeIsOldClosure(
				$this->params['loopId'],
				$reindexAfterLoops
			);
		}

		$startTime = time();

		$pageCache = new ArrayObject();
		/**
		 * @var Checker[] $checkers
		 */
		$checkers = [];
		$perClusterRemediators = [];
		$perClusterBufferedRemediators = [];
		$logger = LoggerFactory::getInstance( 'CirrusSearch' );
		$assignment = $this->searchConfig->getClusterAssignment();
		foreach ( $connections as $cluster => $connection ) {
			$searcher = new Searcher( $connection, 0, 0, $this->searchConfig, [], null );
			$remediator = $assignment->canWriteToCluster( $cluster, UpdateGroup::SANEITIZER )
				? new QueueingRemediator( $cluster ) : new LogOnlyRemediator( $logger );
			$bufferedRemediator = new BufferedRemediator();
			$checker = new Checker(
				$this->searchConfig,
				$connection,
				$bufferedRemediator,
				$searcher,
				Util::getStatsFactory(),
				false, // logSane
				false, // fastRedirectCheck
				$pageCache,
				$isOld
			);
			$checkers[$cluster] = $checker;
			$perClusterRemediators[$cluster] = $remediator;
			$perClusterBufferedRemediators[$cluster] = $bufferedRemediator;
		}

		$multiClusterRemediator = new MultiClusterRemediatorHelper( $perClusterRemediators, $perClusterBufferedRemediators,
			new AllClustersQueueingRemediator(
				$this->getSearchConfig()->getClusterAssignment(),
				MediaWikiServices::getInstance()->getJobQueueGroup()
			) );

		$ranges = array_chunk( range( $from, $to ), $batchSize );
		foreach ( $ranges as $pageIds ) {
			if ( self::getPressure() > $maxPressure ) {
				$this->retry( "too much pressure on update jobs", reset( $pageIds ) );
				return true;
			}
			if ( time() - $startTime > $maxTime ) {
				$this->retry( "execution time exceeded checker_job_max_time", reset( $pageIds ) );
				return true;
			}
			$pageCache->exchangeArray( [] );
			foreach ( $checkers as $cluster => $checker ) {
				try {
					$checker->check( $pageIds );
				} catch ( CheckerException $checkerException ) {
					$this->retry( "Failed to verify ids on $cluster: " . $checkerException->getMessage(), reset( $pageIds ), $cluster );
					unset( $checkers[$cluster] );
				}
			}
			$multiClusterRemediator->sendBatch();
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
		$jobQueueGroup = MediaWikiServices::getInstance()->getJobQueueGroup();
		foreach ( $queues as $queueName ) {
			$queue = $jobQueueGroup->get( $queueName );
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
		return true;
	}

	/**
	 * Retry the job later with a new from offset
	 * @param string $cause why we retry
	 * @param int $newFrom the new from offset
	 * @param string|null $cluster Cluster job is for
	 */
	private function retry( $cause, $newFrom, $cluster = null ) {
		if ( $this->params['retryCount'] >= self::JOB_MAX_RETRIES ) {
			LoggerFactory::getInstance( 'CirrusSearch' )->info(
				"Sanitize CheckerJob: $cause ({fromPageId}:{toPageId}), Abandonning CheckerJob after {retries} retries " .
				"for {cluster}, (jobs_chunk_size too high?).",
				[
					'retries' => $this->params['retryCount'],
					'fromPageId' => $this->params['fromPageId'],
					'toPageId' => $this->params['toPageId'],
					'cluster' => $cluster ?: 'all clusters'
				]
			);
			return;
		}

		$delay = $this->backoffDelay( $this->params['retryCount'] );
		$params = $this->params;
		if ( $cluster !== null ) {
			$params['cluster'] = $cluster;
		}
		$params['retryCount']++;
		$params['fromPageId'] = $newFrom;
		unset( $params['jobReleaseTimestamp'] );
		$jobQueue = MediaWikiServices::getInstance()->getJobQueueGroup();
		$params += self::buildJobDelayOptions( self::class, $delay, $jobQueue );
		$job = new self( $params );
		LoggerFactory::getInstance( 'CirrusSearch' )->info(
			"Sanitize CheckerJob: $cause ({fromPageId}:{toPageId}), Requeueing CheckerJob " .
			"for {cluster} with a delay of {delay}s.",
			[
				'delay' => $delay,
				'fromPageId' => $job->params['fromPageId'],
				'toPageId' => $job->params['toPageId'],
				'cluster' => $cluster ?: 'all clusters'
			]
		);
		$jobQueue->push( $job );
	}
}
