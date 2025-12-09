<?php
/**
 * @license GPL-2.0-or-later
 */

namespace CirrusSearch\Sanity;

use MediaWiki\Page\WikiPage;
use MediaWiki\Title\Title;

/**
 * Remediation actions for insanity in the search index.
 */
interface Remediator {
	/**
	 * There is a redirect in the index.
	 * @param string $docId elasticsearch document id of the redirected page
	 * @param WikiPage $page the page in the index
	 * @param string $indexSuffix The index suffix it was found in
	 */
	public function redirectInIndex( string $docId, WikiPage $page, string $indexSuffix );

	/**
	 * A page isn't in the index.
	 * @param WikiPage $page not in the index
	 */
	public function pageNotInIndex( WikiPage $page );

	/**
	 * A non-existent page is in the index.  Odds are good it was deleted.
	 *
	 * @param string $docId elasticsearch document id of the deleted page
	 * @param Title $title title of the page read from the ghost
	 */
	public function ghostPageInIndex( $docId, Title $title );

	/**
	 * An existent page is in more then one index.
	 *
	 * @param string $docId elasticsearch document id
	 * @param WikiPage $page page in too many indexes
	 * @param string $indexSuffix index suffix that the page is in but shouldn't be in
	 */
	public function pageInWrongIndex( $docId, WikiPage $page, $indexSuffix );

	/**
	 * @param string $docId elasticsearch document id
	 * @param WikiPage $page page with outdated document in index
	 * @param string $indexSuffix index contgaining outdated document
	 */
	public function oldVersionInIndex( $docId, WikiPage $page, $indexSuffix );

	/**
	 * @param WikiPage $page Page considered too old in index
	 */
	public function oldDocument( WikiPage $page );
}
