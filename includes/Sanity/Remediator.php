<?php

namespace CirrusSearch\Sanity;

use Title;
use WikiPage;

/**
 * Remediation actions for insanity in the search index.
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

interface Remediator {
	/**
	 * There is a redirect in the index.
	 * @param WikiPage $page the page in the index
	 */
	public function redirectInIndex( WikiPage $page );

	/**
	 * A page isn't in the index.
	 * @param WikiPage $page not in the index
	 */
	public function pageNotInIndex( WikiPage $page );

	/**
	 * A non-existent page is in the index.  Odds are good it was deleted.
	 *
	 * @param string $docId elsaticsearch document id of the deleted page
	 * @param Title $title title of the page read from the ghost
	 */
	public function ghostPageInIndex( $docId, Title $title );

	/**
	 * An existent page is in more then one index.
	 *
	 * @param string $docId elasticsearch document id
	 * @param WikiPage $page page in too many indexes
	 * @param string $indexType index type that the page is in but shouldn't be in
	 */
	public function pageInWrongIndex( $docId, WikiPage $page, $indexType );

	/**
	 * @param string $docId elasticsearch document id
	 * @param WikiPage $page page with outdated document in index
	 * @param string $indexType index contgaining outdated document
	 */
	public function oldVersionInIndex( $docId, WikiPage $page, $indexType );
}

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
	 * @param string $indexType
	 */
	public function pageInWrongIndex( $docId, WikiPage $page, $indexType ) {
	}

	/**
	 * @param string $docId elasticsearch document id
	 * @param WikiPage $page page with outdated document in index
	 * @param string $indexType index contgaining outdated document
	 */
	public function oldVersionInIndex( $docId, WikiPage $page, $indexType ) {
	}
}

/**
 * Decorating Remediator that logs the prints the errors.
 */
class PrintingRemediator implements Remediator {
	private $next;

	/**
	 * Build the remediator.
	 * @param Remediator $next the remediator that this one decorates
	 */
	public function __construct( Remediator $next ) {
		$this->next = $next;
	}

	public function redirectInIndex( WikiPage $page ) {
		$this->log( $page->getId(), $page->getTitle(), 'Redirect in index' );
		$this->next->redirectInIndex( $page );
	}

	public function pageNotInIndex( WikiPage $page ) {
		$this->log( $page->getId(), $page->getTitle(), 'Page not in index' );
		$this->next->pageNotInIndex( $page );
	}

	/**
	 * @param string $docId
	 * @param Title $title
	 */
	public function ghostPageInIndex( $docId, Title $title ) {
		$this->log( $docId, $title, 'Deleted page in index' );
		$this->next->ghostPageInIndex( $docId, $title );
	}

	/**
	 * @param string $docId
	 * @param WikiPage $page
	 * @param string $indexType
	 */
	public function pageInWrongIndex( $docId, WikiPage $page, $indexType ) {
		$this->log( $page->getId(), $page->getTitle(), "Page in wrong index: $indexType" );
		$this->next->pageInWrongIndex( $docId, $page, $indexType );
	}

	/**
	 * @param string $docId elasticsearch document id
	 * @param WikiPage $page page with outdated document in index
	 * @param string $indexType index contgaining outdated document
	 */
	public function oldVersionInIndex( $docId, WikiPage $page, $indexType ) {
		$this->log( $page->getId(), $page->getTitle(), "Outdated page in index: $indexType" );
		$this->next->oldVersionInIndex( $docId, $page, $indexType );
	}

	/**
	 * @param int|string $pageOrDocId
	 * @param Title $title
	 * @param string $message
	 */
	private function log( $pageOrDocId, $title, $message ) {
		printf( "%30s %10d %s\n", $message, $pageOrDocId, $title );
	}
}
