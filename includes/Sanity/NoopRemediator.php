<?php

namespace CirrusSearch\Sanity;

use MediaWiki\Title\Title;
use WikiPage;

/**
 * Remediator that takes no actions.
 */
class NoopRemediator implements Remediator {

	/**
	 * @inheritDoc
	 */
	public function redirectInIndex( string $docId, WikiPage $page, string $indexSuffix ) {
	}

	/**
	 * @inheritDoc
	 */
	public function pageNotInIndex( WikiPage $page ) {
	}

	/**
	 * @inheritDoc
	 */
	public function ghostPageInIndex( $docId, Title $title ) {
	}

	/**
	 * @inheritDoc
	 */
	public function pageInWrongIndex( $docId, WikiPage $page, $indexSuffix ) {
	}

	/**
	 * @inheritDoc
	 */
	public function oldVersionInIndex( $docId, WikiPage $page, $indexSuffix ) {
	}

	/**
	 * @inheritDoc
	 */
	public function oldDocument( WikiPage $page ) {
	}
}
