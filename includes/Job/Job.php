<?php

namespace CirrusSearch\Job;

use CirrusSearch\Connection;
use CirrusSearch\Updater;
use CirrusSearch\SearchConfig;
use Job as MWJob;
use JobQueueGroup;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use Title;

/**
 * Abstract job class used by all CirrusSearch*Job classes
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
abstract class Job extends MWJob {
	/**
	 * @var Connection
	 */
	protected $connection;

	/**
	 * @var SearchConfig
	 */
	protected $searchConfig;

	/**
	 * @var bool should we retry if this job failed
	 */
	private $allowRetries = true;

	/**
	 * @param Title $title
	 * @param array $params
	 */
	public function __construct( $title, $params ) {
		$params += [ 'cluster' => null ];
		// eg: DeletePages -> cirrusSearchDeletePages
		$jobName = 'cirrusSearch' . str_replace( 'CirrusSearch\\Job\\', '', static::class );
		parent::__construct( $jobName, $title, $params );

		// All CirrusSearch jobs are reasonably expensive.  Most involve parsing and it
		// is ok to remove duplicate _unclaimed_ cirrus jobs.  Once a cirrus job is claimed
		// it can't be deduplicated or else the search index will end up with out of date
		// data.  Luckily, this is how the JobQueue implementations work.
		$this->removeDuplicates = true;

		$this->searchConfig = MediaWikiServices::getInstance()
			->getConfigFactory()
			->makeConfig( 'CirrusSearch' );
		// When the 'cluster' parameter is provided the job must only operate on
		// the specified cluster, take special care to ensure nested jobs get the
		// correct cluster set.  When set to null all clusters should be written to.
		$this->connection = Connection::getPool( $this->searchConfig, $params['cluster'] );
	}

	public function setConnection( Connection $connection ) {
		$this->connection = $connection;
	}

	/**
	 * Some boilerplate stuff for all jobs goes here
	 *
	 * @return bool
	 */
	public function run() {
		global $wgDisableSearchUpdate, $wgPoolCounterConf;

		if ( $wgDisableSearchUpdate ) {
			return true;
		}

		// Make sure we don't flood the pool counter.  This is safe since this is only used
		// to batch update wikis and we don't want to subject those to the pool counter.
		$backupPoolCounterSearch = null;
		if ( isset( $wgPoolCounterConf['CirrusSearch-Search'] ) ) {
			$backupPoolCounterSearch = $wgPoolCounterConf['CirrusSearch-Search'];
			unset( $wgPoolCounterConf['CirrusSearch-Search'] );
		}

		try {
			$ret = $this->doJob();
		} finally {
			// Restore the pool counter settings in case other jobs need them
			if ( $backupPoolCounterSearch ) {
				$wgPoolCounterConf['CirrusSearch-Search'] = $backupPoolCounterSearch;
			}
		}

		return $ret;
	}

	/**
	 * Set a delay for this job.  Note that this might not be possible the JobQueue
	 * implementation handling this job doesn't support it (JobQueueDB) but is possible
	 * for the high performance JobQueueRedis.  Note also that delays are minimums -
	 * at least JobQueueRedis makes no effort to remove the delay as soon as possible
	 * after it has expired.  By default it only checks every five minutes or so.
	 * Note yet again that if another delay has been set that is longer then this one
	 * then the _longer_ delay stays.
	 *
	 * @param int $delay seconds to delay this job if possible
	 */
	public function setDelay( $delay ) {
		$jobQueue = JobQueueGroup::singleton()->get( $this->getType() );
		if ( !$delay || !$jobQueue->delayedJobsEnabled() ) {
			return;
		}
		$oldTime = $this->getReleaseTimestamp();
		$newTime = time() + $delay;
		if ( $oldTime != null && $oldTime >= $newTime ) {
			return;
		}
		$this->params[ 'jobReleaseTimestamp' ] = $newTime;
	}

	/**
	 * Create an Updater instance that will respect cluster configuration
	 * settings of this job.
	 *
	 * @return Updater
	 */
	protected function createUpdater() {
		$flags = [];
		if ( isset( $this->params['cluster'] ) ) {
			$flags[] = 'same-cluster';
		}
		return new Updater( $this->connection, $this->searchConfig, $flags );
	}

	/**
	 * Actually perform the labor of the job
	 *
	 * @return bool
	 */
	abstract protected function doJob();

	/**
	 * @inheritDoc
	 */
	public function allowRetries() {
		return $this->allowRetries;
	}

	/**
	 * @param bool $allowRetries Whether this job should be retried if it fails
	 */
	protected function setAllowRetries( $allowRetries ) {
		$this->allowRetries = $allowRetries;
	}

	/**
	 * @param int $retryCount The number of times the job has errored out.
	 * @return int Number of seconds to delay. With the default minimum exponent
	 *  of 6 the possible return values are  64, 128, 256, 512 and 1024 giving a
	 *  maximum delay of 17 minutes.
	 */
	public static function backoffDelay( $retryCount ) {
		global $wgCirrusSearchWriteBackoffExponent;
		return ceil( pow( 2, $wgCirrusSearchWriteBackoffExponent + rand( 0, min( $retryCount, 4 ) ) ) );
	}

	/**
	 * Construct the list of connections suited for this job.
	 * NOTE: only suited for jobs that work on multiple clusters by
	 * inspecting the 'cluster' job param
	 *
	 * @return Connection[] indexed by cluster name
	 */
	protected function decideClusters() {
		$cluster = isset( $this->params['cluster'] ) ? $this->params['cluster'] : null;
		if ( $cluster === null ) {
			$conns = Connection::getWritableClusterConnections( $this->searchConfig );
		} else {
			if ( !$this->searchConfig->canWriteToCluster( $cluster ) ) {
				// Just in case a job is present in the queue but its cluster
				// has been removed from the config file.
				LoggerFactory::getInstance( 'CirrusSearch' )->warning(
					"Received {command} job for unwritable cluster {cluster}",
					[
						'command' => $this->command,
						'cluster' => $cluster
					]
				);
				// this job does not allow retries so we just need to throw an exception
				throw new \RuntimeException( "Received {$this->command} job for an unwritable cluster $cluster." );
			}
			$conns = [ $cluster => Connection::getPool( $this->searchConfig, $cluster ) ];
		}

		$timeout = $this->searchConfig->get( 'CirrusSearchClientSideUpdateTimeout' );
		foreach ( $conns as $connection ) {
			$connection->setTimeout( $timeout );
		}

		return $conns;
	}
}
