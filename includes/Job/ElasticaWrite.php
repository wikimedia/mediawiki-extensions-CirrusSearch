<?php

namespace CirrusSearch\Job;

use CirrusSearch\Connection;
use CirrusSearch\DataSender;
use \JobQueueGroup;
use MediaWiki\Logger\LoggerFactory;

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
	public function __construct( $title, $params ) {
		parent::__construct( $title, $params + array(
			'createdAt' => time(),
			'errorCount' => 0,
		) );
	}

	protected function doJob() {
		global $wgCirrusSearchDropDelayedJobsAfter;

		if ( $this->params['clientSideTimeout'] ) {
			Connection::setTimeout( $this->params['clientSideTimeout'] );
		}

		LoggerFactory::getInstance( 'CirrusSearch' )->debug(
			"Running {$this->params['method']} for " . json_encode( $this->params['arguments'] )
		);
		$sender = new DataSender();
		$status = call_user_func_array(
			array( $sender, $this->params['method'] ),
			$this->params['arguments']
		);

		if ( $status->hasMessage( 'cirrussearch-indexes-frozen' ) ) {
			$diff = time() - $this->params['createdAt'];
			if ( $diff > $wgCirrusSearchDropDelayedJobsAfter ) {
				LoggerFactory::getInstance( 'CirrusSearchChangeFailed' )->warning(
					"Dropping delayed job for DataSender::{$this->params['method']} after waiting {$diff}s" );
			} else {
				$delay = self::backoffDelay( $this->params['errorCount'] );
				LoggerFactory::getInstance( 'CirrusSearch' )->debug(
					"Requeueing job with frozen indexes to be run {$delay}s later");
				++$this->params['errorCount'];
				$this->setDelay( $delay );
				JobQueueGroup::singleton()->push( $this );
			}

		} elseif ( !$status->isOK() ) {
			// Individual failures should have already logged specific errors,
			// returning false here will requeue the job to be run at a later time.
			LoggerFactory::getInstance( 'CirrusSearch' )->debug(
				"Job reported failure, allowing job queue to requeue" );
			return false;
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
