<?php

namespace CirrusSearch\Job;

use CirrusSearch\Updater;
use MediaWiki\Title\Title;

/**
 * Updates link counts to page when it is newly linked or unlinked.
 *
 * @license GPL-2.0-or-later
 */
class IncomingLinkCount extends CirrusTitleJob {
	public function __construct( Title $title, array $params ) {
		parent::__construct( $title, $params );
	}

	/**
	 * @return bool
	 */
	protected function doJob() {
		// Load the titles and filter out any that no longer exist.
		$updater = Updater::build( $this->getSearchConfig(), $this->params['cluster'] ?? null );
		// We're intentionally throwing out whether or not this job succeeds.
		// We're logging it but we're not retrying.
		$updater->updateLinkedArticles( [ $this->getTitle() ] );
		return true;
	}
}
