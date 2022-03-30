<?php

namespace CirrusSearch\Sanity;

use Title;
use WikiPage;

/**
 * Remediator that takes no actions.
 */
class NoopRemediator implements Remediator {

	/**
	 * @param WikiPage $page
	 */
	public function redirectInIndex( WikiPage $page ) {
	}

	/**
	 * @param WikiPage $page
	 */
	public function pageNotInIndex( WikiPage $page ) {
	}

	/**
	 * @param string $docId
	 * @param Title $title
	 */
	public function ghostPageInIndex( $docId, Title $title ) {
	}

	/**
	 * @param string $docId
	 * @param WikiPage $page
	 * @param string $indexSuffix
	 */
	public function pageInWrongIndex( $docId, WikiPage $page, $indexSuffix ) {
	}

	/**
	 * @param string $docId elasticsearch document id
	 * @param WikiPage $page page with outdated document in index
	 * @param string $indexSuffix index contgaining outdated document
	 */
	public function oldVersionInIndex( $docId, WikiPage $page, $indexSuffix ) {
	}

	/**
	 * @param WikiPage $page Page considered too old in index
	 */
	public function oldDocument( WikiPage $page ) {
	}
}
