<?php
/**
 * @license GPL-2.0-or-later
 */

namespace CirrusSearch\Sanity;

use CirrusSearch\Job\DeletePages;
use CirrusSearch\Job\LinksUpdate;
use CirrusSearch\Job\UpdateRedirectDocument;
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
	 * @var bool Whether redirect pages have their own first-class documents
	 *  (CirrusSearchRedirectDocuments['build']).
	 */
	private $buildRedirectDocuments;

	/**
	 * @param string|null $cluster The name of the cluster to update,
	 *  or null to update all clusters.
	 * @param JobQueueGroup|null $jobQueueGroup
	 * @param bool $buildRedirectDocuments Whether redirect pages have their own
	 *  first-class documents. When true, redirect remediation writes the redirect's
	 *  own document instead of tracing to the target.
	 */
	public function __construct(
		$cluster,
		?JobQueueGroup $jobQueueGroup = null,
		bool $buildRedirectDocuments = false
	) {
		$this->cluster = $cluster;
		$this->jobQueue = $jobQueueGroup ?? MediaWikiServices::getInstance()->getJobQueueGroup();
		$this->buildRedirectDocuments = $buildRedirectDocuments;
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
		// Only reached when redirect documents are not built, so the routing below
		// traces this redirect to its target via a links update.
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
		// When redirect documents are built, route remediation through UpdateRedirectDocument so
		// the redirect's document is (re)written as necessary.
		if ( $this->buildRedirectDocuments && $page->isRedirect() ) {
			$this->jobQueue->push(
				UpdateRedirectDocument::newSaneitizerUpdate( $page->getTitle(), $this->cluster )
			);
		// Otherwise a LinksUpdate on a redirect traces to its target and updates the target
		} else {
			$this->jobQueue->push( LinksUpdate::newSaneitizerUpdate( $page->getTitle(), $this->cluster ) );
		}
	}
}
