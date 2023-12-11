<?php

namespace CirrusSearch\Job;

use CirrusSearch\Updater;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
use MediaWiki\Utils\MWTimestamp;

/**
 * Performs the appropriate updates to Elasticsearch after a LinksUpdate is
 * completed.  The page itself is updated first then a second copy of this job
 * is queued to update linked articles if any links change.  The job can be
 * 'prioritized' via the 'prioritize' parameter which will switch it to a
 * different queue then the non-prioritized jobs.  Prioritized jobs will never
 * be deduplicated with non-prioritized jobs which is good because we can't
 * control which job is removed during deduplication.  In our case it'd only be
 * ok to remove the non-prioritized version.
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
class LinksUpdate extends CirrusTitleJob {
	/**
	 * param key to determine if the job should be "prioritized"
	 */
	private const PRIORITIZE = 'prioritize';

	public function __construct( Title $title, array $params ) {
		parent::__construct( $title, $params );

		if ( $this->isPrioritized() ) {
			$this->command .= 'Prioritized';
		}
		// Note that we have to keep the prioritized param or else when the job
		// is loaded it'll load under a different name/command/type which would
		// be confusing.
	}

	/**
	 * Prepare a page update for when this page is directly updated (new revision/delete/restore)
	 *
	 * @param Title $title
	 * @param RevisionRecord|null $revisionRecord
	 * @param array $params
	 * @return LinksUpdate
	 */
	public static function newPageChangeUpdate( Title $title, ?RevisionRecord $revisionRecord, array $params ): LinksUpdate {
		if ( $revisionRecord !== null && $revisionRecord->getTimestamp() !== null ) {
			$ts = (int)MWTimestamp::convert( TS_UNIX, $revisionRecord->getTimestamp() );
		} else {
			$ts = MWTimestamp::time();
		}
		$params += [
			self::PRIORITIZE => true,
			self::UPDATE_KIND => self::PAGE_CHANGE,
			self::ROOT_EVENT_TIME => $ts,
		];

		return new self( $title, $params );
	}

	/**
	 * Prepare a cautionary update of a page that had some of its revision's visibility changed.
	 * (Theoretically not required because old revisions should not be part of the index)
	 * @param Title $title
	 * @return LinksUpdate
	 */
	public static function newPastRevisionVisibilityChange( Title $title ): LinksUpdate {
		$params = [
			self::PRIORITIZE => true,
			self::UPDATE_KIND => self::VISIBILITY_CHANGE,
			self::ROOT_EVENT_TIME => MWTimestamp::time(),
		];

		return new self( $title, $params );
	}

	/**
	 * Prepare a page update for when the rendered output of the page might have changed due to a
	 * change not directly related to this page (e.g. template update).
	 *
	 * @param Title $title
	 * @param array $params
	 * @return LinksUpdate
	 */
	public static function newPageRefreshUpdate( Title $title, array $params ): LinksUpdate {
		$params += [
			self::PRIORITIZE => false,
			self::UPDATE_KIND => self::PAGE_REFRESH,
			self::ROOT_EVENT_TIME => MWTimestamp::time(),
		];
		return new self( $title, $params );
	}

	/**
	 * New change emitted from the saneitizer
	 * @param Title $title
	 * @param string|null $cluster optional target cluster, null for all clusters
	 * @return LinksUpdate
	 */
	public static function newSaneitizerUpdate( Title $title, ?string $cluster ): LinksUpdate {
		$params = [
			self::PRIORITIZE => false,
			self::UPDATE_KIND => self::SANEITIZER,
			self::ROOT_EVENT_TIME => MWTimestamp::time(),
			self::CLUSTER => $cluster
		];
		return new self( $title, $params );
	}

	/**
	 * @return bool
	 */
	protected function doJob() {
		$updater = Updater::build( $this->getSearchConfig(), $this->params['cluster'] ?? null );
		if ( $this->params[self::UPDATE_KIND] === self::SANEITIZER ) {
			$this->saneitize( $updater );
		} else {
			$this->update( $updater );
		}

		if ( $this->getSearchConfig()->get( 'CirrusSearchEnableIncomingLinkCounting' ) ) {
			$this->queueIncomingLinksJobs();
		}

		return true;
	}

	/**
	 * Indirection doing technically nothing but help measure the impact of these jobs via flame graphs.
	 * @param Updater $updater
	 * @return void
	 */
	private function saneitize( Updater $updater ): void {
		$this->update( $updater );
	}

	private function update( Updater $updater ): void {
		$updater->updateFromTitle( $this->title, $this->params[self::UPDATE_KIND], $this->params[self::ROOT_EVENT_TIME] );
	}

	/**
	 * Queue IncomingLinkCount jobs when pages are newly linked or unlinked
	 */
	private function queueIncomingLinksJobs() {
		$titleKeys = array_merge( $this->params[ 'addedLinks' ] ?? [],
			$this->params[ 'removedLinks' ] ?? [] );
		$refreshInterval = $this->getSearchConfig()->get( 'CirrusSearchRefreshInterval' );
		$jobs = [];
		$jobQueue = MediaWikiServices::getInstance()->getJobQueueGroup();
		foreach ( $titleKeys as $titleKey ) {
			$title = Title::newFromDBkey( $titleKey );
			if ( !$title || !$title->canExist() ) {
				continue;
			}
			// If possible, delay the job execution by a few seconds so Elasticsearch
			// can refresh to contain what we just sent it.  The delay should be long
			// enough for Elasticsearch to complete the refresh cycle, which normally
			// takes wgCirrusSearchRefreshInterval seconds but we double it and add
			// one just in case.
			$delay = 2 * $refreshInterval + 1;
			$jobs[] = new IncomingLinkCount( $title, [
				'cluster' => $this->params['cluster'],
			] + self::buildJobDelayOptions( IncomingLinkCount::class, $delay, $jobQueue ) );
		}
		$jobQueue->push( $jobs );
	}

	/**
	 * @return bool Is this job prioritized?
	 */
	public function isPrioritized() {
		return isset( $this->params[self::PRIORITIZE] ) && $this->params[self::PRIORITIZE];
	}
}
