<?php

namespace CirrusSearch\Job;

use CirrusSearch\Updater;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\WikiPage;
use MediaWiki\Title\Title;

/**
 * Job wrapper around Updater::updatePages.  Used by forceSearchIndex.php
 * when in job queueing mode.
 *
 * @license GPL-2.0-or-later
 */
class MassIndex extends CirrusGenericJob {
	/**
	 * @param WikiPage[] $pages
	 * @param int $updateFlags
	 * @param string|null $cluster
	 * @return self
	 */
	public static function build( array $pages, $updateFlags, $cluster = null ) {
		// Strip $pages down to PrefixedDBKeys so we don't put a ton of stuff in the job queue.
		$pageDBKeys = [];
		foreach ( $pages as $page ) {
			$pageDBKeys[] = $page->getTitle()->getPrefixedDBkey();
		}

		// We don't have a "title" for this job so we use the Main Page because it exists.
		return new self( [
			'pageDBKeys' => $pageDBKeys,
			'updateFlags' => $updateFlags,
			'cluster' => $cluster,
		] );
	}

	/**
	 * @return bool
	 */
	protected function doJob() {
		// Reload pages from pageIds to throw into the updater
		$pageData = [];
		$wikiPageFactory = MediaWikiServices::getInstance()->getWikiPageFactory();
		foreach ( $this->params[ 'pageDBKeys' ] as $pageDBKey ) {
			$title = Title::newFromDBkey( $pageDBKey );
			// Skip any titles with broken keys.  We can't do anything with them.
			if ( !$title ) {
				LoggerFactory::getInstance( 'CirrusSearch' )->warning(
					"Skipping invalid DBKey: {pageDBKey}",
					[ 'pageDBKey' => $pageDBKey ]
				);
				continue;
			}
			$pageData[] = $wikiPageFactory->newFromTitle( $title );
		}
		// Now invoke the updater!
		$updater = Updater::build( $this->searchConfig, $this->params['cluster'] ?? null );
		$updater->updatePages( $pageData, $this->params[ 'updateFlags' ] );
		// retries are handled in a separate queue
		return true;
	}

	/**
	 * @return int
	 */
	public function workItemCount() {
		return count( $this->params[ 'pageDBKeys' ] );
	}
}
