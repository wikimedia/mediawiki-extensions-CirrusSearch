<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\Connection;
use CirrusSearch\Job\CheckerJob;

use CirrusSearch\Profile\SearchProfileService;
use JobQueueGroup;

/**
 * Push some sanitize jobs to the JobQueue
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

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
require_once __DIR__ . '/../includes/Maintenance/Maintenance.php';

class SaneitizeJobs extends Maintenance {
	/**
	 * @var MetaStoreIndex[] all metastores for write clusters
	 */
	private $metaStores;

	/**
	 * @var int min page id (from db)
	 */
	private $minId;

	/**
	 * @var int max page id (from db)
	 */
	private $maxId;

	/**
	 * @var string profile name
	 */
	private $profileName;

	public function __construct() {
		parent::__construct();
		$this->mDescription = 'Manage sanitize jobs (CheckerJob). This ' .
			'script operates on all writable clusters by default. ' .
			'Add --cluster to work on a single cluster. Note that ' .
			'once a job has been pushed to a particular cluster the ' .
			'script will fail if you try to run the same job with ' .
			'different cluster options.';
		$this->addOption( 'push', 'Push some jobs to the job queue.' );
		$this->addOption( 'show', 'Display job info.' );
		$this->addOption( 'delete-job', 'Delete the job.' );
		$this->addOption( 'refresh-freq', 'Refresh rate in seconds this ' .
			'script is run from your crontab. This will be '.
			'used to spread jobs over time. Defaults to 7200 (2 ' .
			'hours).', false, true );
		$this->addOption( 'job-name', 'Tells the script the name of the ' .
			'sanitize job only useful to run multiple sanitize jobs. ' .
			'Defaults to "default".', false, true );
	}

	public function execute() {
		$this->init();
		if ( $this->hasOption( 'show' ) ) {
			$this->showJobDetail();
		} elseif ( $this->hasOption( 'push' ) ) {
			$this->pushJobs();
		} elseif ( $this->hasOption( 'delete-job' ) ) {
			$this->deleteJob();
		} else {
			$this->maybeHelp( true );
		}
	}

	private function init() {
		$res = $this->getDB( DB_REPLICA )->select( 'page',
			[ 'MIN(page_id) as min_id', 'MAX(page_id) as max_id' ] );
		$row = $res->next();
		/** @suppress PhanUndeclaredProperty */
		$this->minId = $row->min_id;
		/** @suppress PhanUndeclaredProperty */
		$this->maxId = $row->max_id;
		$profiles = $this->getSearchConfig()->getProfileService()
			->listExposedProfiles( SearchProfileService::SANEITIZER );
		uasort( $profiles, function ( $a, $b ) {
			return $a['max_wiki_size'] < $b['max_wiki_size'] ? -1 : 1;
		} );
		$wikiSize = $this->maxId - $this->minId;
		foreach ( $profiles as $name => $settings ) {
			if ( $settings['max_wiki_size'] > $wikiSize ) {
				$this->profileName = $name;
				$this->log( "Detected $wikiSize ids to check, selecting profile $name\n" );
				break;
			}
		}
		if ( !$this->profileName ) {
			$this->fatalError( "No profile found for $wikiSize ids, please check sanitization profiles" );
		}
	}

	private function deleteJob() {
		$jobName = $this->getOption( 'job-name', 'default' );
		$this->initMetaStores();
		$jobInfo = $this->getJobInfo( $jobName );
		if ( $jobInfo === null ) {
			$this->fatalError( "Unknown job $jobName\n" );
		}
		foreach ( $this->metaStores as $cluster => $store ) {
			$store->sanitizeType()->deleteDocument( $jobInfo );
			$this->log( "Deleted job $jobName from $cluster.\n" );
		}
	}

	/**
	 * Basically we support two modes:
	 *   - all writable cluster, cluster = null
	 *   - single cluster, cluster = 'clusterName'
	 * If we detect a mismatch here we fail.
	 * @param \Elastica\Document $jobInfo check if the stored job match
	 * cluster config used by this script, will die if clusters mismatch
	 */
	private function checkJobClusterMismatch( \Elastica\Document $jobInfo ) {
		$jobCluster = $jobInfo->get( 'sanitize_job_cluster' );
		$scriptCluster = $this->getOption( 'cluster' );
		if ( $jobCluster != $scriptCluster ) {
			$jobCluster = $jobCluster != null ? $jobCluster : "all writable clusters";
			$scriptCluster = $scriptCluster != null ? $scriptCluster : "all writable clusters";
			$this->fatalError( "Job cluster mismatch, stored job is configured to work on $jobCluster " .
				"but the script is configured to run on $scriptCluster.\n" );
		}
	}

	private function showJobDetail() {
		$profile = $this->getSearchConfig()
			->getProfileService()
			->loadProfileByName( SearchProfileService::SANEITIZER, $this->profileName );
		$minLoopDuration = $profile['min_loop_duration'];
		$maxJobs = $profile['max_checker_jobs'];
		$maxUpdates = $profile['update_jobs_max_pressure'];

		$this->initMetaStores();
		$jobName = $this->getOption( 'job-name', 'default' );
		$jobInfo = $this->getJobInfo( $jobName );
		if ( $jobInfo === null ) {
			$this->fatalError( "Unknown job $jobName, push some jobs first.\n" );
		}
		$fmt = 'Y-m-d H:i:s';
		$cluster = $jobInfo->get( 'sanitize_job_cluster' ) ?: 'All writable clusters';

		$created = date( $fmt, $jobInfo->get( 'sanitize_job_created' ) );
		$updated = date( $fmt, $jobInfo->get( 'sanitize_job_updated' ) );
		$loopStart = date( $fmt, $jobInfo->get( 'sanitize_job_last_loop' ) );

		$idsSent = $jobInfo->get( 'sanitize_job_ids_sent' );
		$idsSentTotal = $jobInfo->get( 'sanitize_job_ids_sent_total' );

		$jobsSent = $jobInfo->get( 'sanitize_job_jobs_sent' );
		$jobsSentTotal = $jobInfo->get( 'sanitize_job_jobs_sent_total' );

		$updatePressure = CheckerJob::getPressure();

		$loopTime = time() - $jobInfo->get( 'sanitize_job_last_loop' );
		$totalTime = time() - $jobInfo->get( 'sanitize_job_created' );

		$jobsRate = $jobInfo->get( 'sanitize_job_jobs_sent' ) / $loopTime;
		$jobsPerHour = round( $jobsRate * 3600, 2 );
		$jobsPerDay = round( $jobsRate * 3600 * 24, 2 );
		$jobsRateTotal = $jobInfo->get( 'sanitize_job_jobs_sent_total' ) / $totalTime;
		$jobsTotalPerHour = round( $jobsRateTotal * 3600, 2 );
		$jobsTotalPerDay = round( $jobsRateTotal * 3600 * 24, 2 );

		$idsRate = $jobInfo->get( 'sanitize_job_ids_sent' ) / $loopTime;
		$idsPerHour = round( $idsRate * 3600, 2 );
		$idsPerDay = round( $idsRate * 3600 * 24, 2 );
		$idsRateTotal = $jobInfo->get( 'sanitize_job_ids_sent_total' ) / $totalTime;
		$idsTotalPerHour = round( $idsRateTotal * 3600, 2 );
		$idsTotalPerDay = round( $idsRateTotal * 3600 * 24, 2 );

		$idsTodo = $this->maxId - $jobInfo->get( 'sanitize_job_id_offset' );
		$loopEta = date( $fmt, time() + ( $idsTodo * $jobsRate ) );
		$loopRestartMinTime = date( $fmt, $jobInfo->get( 'sanitize_job_last_loop' ) + $minLoopDuration );

		$this->output( <<<EOD
JobDetail for {$jobName}
	Target Wiki: 	{$jobInfo->get( 'sanitize_job_wiki' )}
	Cluster: 	{$cluster}
	Created: 	{$created}
	Updated: 	{$updated}
	Loop start:	{$loopStart}
	Current id:	{$jobInfo->get( 'sanitize_job_id_offset' )}
	Ids sent:	{$idsSent} ({$idsSentTotal} total)
	Jobs sent:	{$jobsSent} ({$jobsSentTotal} total)
	Pressure (CheckerJobs):
		Cur:	{$this->getPressure()} jobs
		Max:	{$maxJobs} jobs
	Pressure (Updates):
		Cur:	{$updatePressure} jobs
		Max:	{$maxUpdates} jobs
	Jobs rate:
		Loop:	{$jobsPerHour} jobs/hour, {$jobsPerDay} jobs/day
		Total:	{$jobsTotalPerHour} jobs/hour, {$jobsTotalPerDay} jobs/day
	Ids rate :
		Loop:	{$idsPerHour} ids/hour, {$idsPerDay} ids/day
		Total:	{$idsTotalPerHour} ids/hour, {$idsTotalPerDay} ids/day
	Loop:
		Todo:	{$idsTodo} ids
		ETA:	{$loopEta}
	Loop restart min time: {$loopRestartMinTime}

EOD
		);
	}

	private function pushJobs() {
		$pushJobFreq = $this->getOption( 'refresh-freq', 2 * 3600 );
		if ( !$this->getSearchConfig()->get( 'CirrusSearchSanityCheck' ) ) {
			$this->fatalError( "Sanity check disabled, abandonning...\n" );
		}
		$profile = $this->getSearchConfig()
			->getProfileService()
			->loadProfileByName( SearchProfileService::SANEITIZER, $this->profileName );
		$chunkSize = $profile['jobs_chunk_size'];
		$maxJobs = $profile['max_checker_jobs'];
		if ( !$maxJobs || $maxJobs <= 0 ) {
			$this->fatalError( "max_checker_jobs invalid abandonning.\n" );
		}
		$minLoopDuration = $profile['min_loop_duration'];

		$pressure = $this->getPressure();
		if ( $pressure >= $maxJobs ) {
			$this->fatalError( "Too many CheckerJob: $pressure in the queue, $maxJobs allowed.\n" );
		}
		$this->log( "$pressure checker job(s) in the queue.\n" );

		$this->disablePoolCountersAndLogging();
		$this->initMetaStores();

		$jobName = $this->getOption( 'job-name', 'default' );
		$jobInfo = $this->getJobInfo( $jobName );
		if ( $jobInfo === null ) {
			$jobInfo = $this->createNewJob( $jobName );
		}
		// @var int
		$from = $jobInfo->get( 'sanitize_job_id_offset' );
		$lastLoop = $jobInfo->get( 'sanitize_job_last_loop' );
		if ( $from <= $this->minId ) {
			// Avoid sending too many CheckerJob for very small wikis
			if ( !$this->checkMinLoopDuration( $lastLoop,  $minLoopDuration ) ) {
				return;
			}
			$lastLoop = time();
		}
		$jobsSent = $jobInfo->get( 'sanitize_job_jobs_sent' );
		$jobsSentTotal = $jobInfo->get( 'sanitize_job_jobs_sent_total' );
		$idsSent = $jobInfo->get( 'sanitize_job_ids_sent' );
		$idsSentTotal = $jobInfo->get( 'sanitize_job_ids_sent_total' );
		for ( $i = 0; $i < $maxJobs; $i++ ) {
			$to = min( $from + $chunkSize - 1, $this->maxId );
			$this->sendJob( $from, $to, $pushJobFreq, $jobInfo->get( 'sanitize_job_cluster' ) );
			$jobsSent++;
			$jobsSentTotal++;
			$idsSent += $to - $from;
			$idsSentTotal += $to - $from;
			$from = $to;
			if ( $from >= $this->maxId ) {
				$from = $this->minId;
				$idsSent = 0;
				$jobsSent = 0;
				if ( !$this->checkMinLoopDuration( $lastLoop, $minLoopDuration ) ) {
					break;
				}
				$lastLoop = time();
			} else {
				$from++;
			}
		}
		$this->log( "Sent $jobsSent jobs, setting from offset to $from.\n" );
		$jobInfo->set( 'sanitize_job_last_loop', $lastLoop );
		$jobInfo->set( 'sanitize_job_id_offset', $from );
		$jobInfo->set( 'sanitize_job_jobs_sent', $jobsSent );
		$jobInfo->set( 'sanitize_job_jobs_sent_total', $jobsSentTotal );
		$jobInfo->set( 'sanitize_job_ids_sent', $idsSent );
		$jobInfo->set( 'sanitize_job_ids_sent_total', $idsSentTotal );
		$this->updateJob( $jobInfo );
	}

	/**
	 * @param int $from
	 * @param int $to
	 * @param int $refreshRate
	 * @param string|null $cluster
	 */
	private function sendJob( $from, $to, $refreshRate, $cluster ) {
		$delay = mt_rand( 0, $refreshRate );
		$this->log( "Pushing CheckerJob( $from, $to, $delay, $cluster )\n" );
		JobQueueGroup::singleton()->push( CheckerJob::build( $from, $to, $delay, $this->profileName, $cluster ) );
	}

	/**
	 * @param int|null $lastLoop last loop start time
	 * @param int $minLoopDuration minimal duration of a loop
	 * @return bool true if minLoopDuration is not reached false otherwize
	 */
	private function checkMinLoopDuration( $lastLoop, $minLoopDuration ) {
		if ( $lastLoop !== null && ( time() - $lastLoop ) < $minLoopDuration ) {
			$date = date( 'Y-m-d H:i:s', $lastLoop );
			$newLoop = date( 'Y-m-d H:i:s', $lastLoop + $minLoopDuration );
			$this->log( "Last loop ended at $date, new jobs will be sent when min_loop_duration is reached at $newLoop\n" );
			return false;
		}
		return true;
	}

	private function initMetaStores() {
		$connections = [];
		if ( $this->hasOption( 'cluster' ) ) {
			$cluster = $this->getOption( 'cluster' );
			if ( !$this->getSearchConfig()->clusterExists( $cluster ) ) {
				$this->fatalError( "Unknown cluster $cluster\n" );
			}
			if ( $this->getSearchConfig()->canWriteToCluster( $cluster ) ) {
				$this->fatalError( "$cluster is not writable\n" );
			}
			$connections[$cluster] = Connection::getPool( $this->getSearchConfig(), $cluster );
		} else {
			$connections = Connection::getWritableClusterConnections( $this->getSearchConfig() );
		}

		if ( empty( $connections ) ) {
			$this->fatalError( "No writable cluster found." );
		}

		$this->metaStores = [];
		foreach ( $connections as $cluster => $connection ) {
			if ( !MetaStoreIndex::cirrusReady( $connection ) ) {
				$this->fatalError( "No metastore found in cluster $cluster" );
			}
			$store = new MetaStoreIndex( $connection, $this );
			if ( !$store->versionIsAtLeast( [ 0, 2 ] ) ) {
				$this->fatalError( 'Metastore version is too old, expected at least 0.2' );
			}
			$this->metaStores[$cluster] = $store;
		}
	}

	/**
	 * @param string $jobName job name.
	 * @return \Elastica\Document|null
	 */
	private function getJobInfo( $jobName ) {
		$latest = null;
		// Fetch the lastest jobInfo from the metastore. Ideally all
		// jobInfo should be the same but in the case a cluster has
		// been decommissioned and re-added its job info may be outdated
		foreach ( $this->metaStores as $metastore ) {
			$current = null;
			try {
				// Try to fetch the JobInfo from one of the metastore
				$current = $metastore->sanitizeType()->getDocument(
					$this->jobId( $jobName )
				);
				$this->checkJobClusterMismatch( $current );
				if ( $latest == null ) {
					$latest = $current;
				/** @suppress PhanNonClassMethodCall $current cannot be null */
				} elseif ( $current->get( 'sanitize_job_updated' ) > $latest->get( 'sanitize_job_updated' ) ) {
					$latest = $current;
				}
			} catch ( \Elastica\Exception\NotFoundException $e ) {
			}
		}
		return $latest;
	}

	/**
	 * @param string $jobName
	 * @return string the job id
	 */
	private function jobId( $jobName ) {
		return 'sanitize-job-' . wfWikiID() . '-' . $jobName;
	}

	/**
	 * @param \Elastica\Document $jobInfo
	 */
	private function updateJob( \Elastica\Document $jobInfo ) {
		$version = time();
		$jobInfo->set( 'sanitize_job_updated', $version );
		$jobInfo->setVersion( $version );
		// @todo: remove this suppress (https://github.com/ruflin/Elastica/pull/1134)
		/** @suppress PhanTypeMismatchArgument this method is improperly annotated */
		$jobInfo->setVersionType( 'external' );
		foreach ( $this->metaStores as $store ) {
			$store->sanitizeType()->addDocument( $jobInfo );
		}
	}

	/**
	 * @param string $jobName
	 * @return \Elastica\Document
	 */
	private function createNewJob( $jobName ) {
		reset( $this->metaStores );
		$cluster = $this->getOption( 'cluster' );
		$job = new \Elastica\Document(
			$this->jobId( $jobName ),
			[
				'sanitize_job_wiki' => wfWikiID(),
				'sanitize_job_created' => time(),
				'sanitize_job_updated' => time(),
				'sanitize_job_last_loop' => null,
				'sanitize_job_cluster' => $cluster,
				'sanitize_job_id_offset' => $this->minId,
				'sanitize_job_ids_sent' => 0,
				'sanitize_job_ids_sent_total' => 0,
				'sanitize_job_jobs_sent' => 0,
				'sanitize_job_jobs_sent_total' => 0
			]
		);
		foreach ( $this->metaStores as $store ) {
			$store->sanitizeType()->addDocument( $job );
		}
		return $job;
	}

	/**
	 * @return int the number of jobs in the CheckerJob queue
	 */
	private function getPressure() {
		$queue = JobQueueGroup::singleton()->get( 'cirrusSearchCheckerJob' );
		return $queue->getSize() + $queue->getDelayedCount();
	}

	private function log( $msg, $channel = null ) {
		$date = new \DateTime();
		$this->output( $date->format( 'Y-m-d H:i:s' ) . " " . $msg, $channel );
	}

	/**
	 * @param string $msg The error to display
	 * @param int $die deprecated do not use
	 */
	public function error( $msg, $die = 0 ) {
		$date = new \DateTime();
		parent::error( $date->format( 'Y-m-d H:i:s' ) . " " . $msg );
	}

	/**
	 * @param string $msg The error to display
	 * @param int $exitCode die out using this int as the code
	 */
	public function fatalError( $msg, $exitCode = 1 ) {
		$date = new \DateTime();
		parent::fatalError( $date->format( 'Y-m-d H:i:s' ) . " " . $msg, $exitCode );
	}
}

$maintClass = "CirrusSearch\Maintenance\SaneitizeJobs";
require_once RUN_MAINTENANCE_IF_MAIN;
