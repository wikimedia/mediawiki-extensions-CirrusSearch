<?php
/**
 * @license GPL-2.0-or-later
 */

namespace CirrusSearch\Sanity;

use CirrusSearch\Job\DeletePages;
use CirrusSearch\Job\LinksUpdate;
use MediaWiki\JobQueue\JobQueueGroup;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\WikiPage;
use MediaWiki\Title\Title;

/**
 * Remediator implementation that queues jobs to fix the index.
 */
class QueueingRemediator implements Remediator {
	/**
	 * @var string|null
	 */
	protected $cluster;

	/**
	 * @var JobQueueGroup
	 */
	private $jobQueue;

	/**
	 * @param string|null $cluster The name of the cluster to update,
	 *  or null to update all clusters.
	 * @param JobQueueGroup|null $jobQueueGroup
	 */
	public function __construct( $cluster, ?JobQueueGroup $jobQueueGroup = null ) {
		$this->cluster = $cluster;
		$this->jobQueue = $jobQueueGroup ?? MediaWikiServices::getInstance()->getJobQueueGroup();
	}

	/**
	 * @inheritDoc
	 */
	public function redirectInIndex( string $docId, WikiPage $page, string $indexSuffix ) {
		// Links update job will delete this if $indexSuffix is the expected one,
		// but if it's in the wrong index we need an explicit delete.
		$this->jobQueue->push(
			new DeletePages( $page->getTitle(), [
				'indexSuffix' => $indexSuffix,
				'docId' => $docId,
				'cluster' => $this->cluster,
			] )
		);
		$this->pushLinksUpdateJob( $page );
	}

	/**
	 * @inheritDoc
	 */
	public function pageNotInIndex( WikiPage $page ) {
		$this->pushLinksUpdateJob( $page );
	}

	/**
	 * @inheritDoc
	 */
	public function ghostPageInIndex( $docId, Title $title ) {
		$this->jobQueue->push(
			new DeletePages( $title, [
				'docId' => $docId,
				'cluster' => $this->cluster,
			] )
		);
	}

	/**
	 * @inheritDoc
	 */
	public function pageInWrongIndex( $docId, WikiPage $page, $wrongIndex ) {
		$this->jobQueue->push(
			new DeletePages( $page->getTitle(), [
				'indexSuffix' => $wrongIndex,
				'docId' => $docId,
				'cluster' => $this->cluster,
			] )
		);
		$this->pushLinksUpdateJob( $page );
	}

	/**
	 * @inheritDoc
	 */
	public function oldVersionInIndex( $docId, WikiPage $page, $index ) {
		$this->pushLinksUpdateJob( $page );
	}

	/**
	 * @inheritDoc
	 */
	public function oldDocument( WikiPage $page ) {
		$this->pushLinksUpdateJob( $page );
	}

	private function pushLinksUpdateJob( WikiPage $page ) {
		$this->jobQueue->push( LinksUpdate::newSaneitizerUpdate( $page->getTitle(), $this->cluster ) );
	}
}
