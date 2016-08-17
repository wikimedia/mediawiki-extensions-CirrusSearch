<?php

namespace CirrusSearch\BuildDocument;

use CirrusSearch\Connection;
use CirrusSearch\ElasticsearchIntermediary;
use CirrusSearch\SearchConfig;
use Elastica\Query\Terms;
use Elastica\Query\BoolQuery;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use SplObjectStorage;
use Title;
use WikiPage;

/**
 * Adds redirects and incoming links to the documents.  These are done together
 * because one needs the other.
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
class RedirectsAndIncomingLinks {
	/**
	 * @param Title $title
	 * @param Connection $conn
	 * @return bool
	 */
	public static function buildDocument( \Elastica\Document $doc, Title $title, Connection $conn ) {
		global $wgCirrusSearchIndexedRedirects;

		$outgoingLinksToCount = array( $title );

		// Gather redirects to this page
		$redirectTitles = $title->getBacklinkCache()
			->getLinks( 'redirect', false, false, $wgCirrusSearchIndexedRedirects );
		$redirects = array();
		foreach ( $redirectTitles as $redirect ) {
			// If the redirect is in main OR the same namespace as the article the index it
			if ( $redirect->getNamespace() === NS_MAIN || $redirect->getNamespace() === $title->getNamespace()) {
				$redirects[] = array(
					'namespace' => $redirect->getNamespace(),
					'title' => $redirect->getText()
				);
				$outgoingLinksToCount[] = $redirect;
			}
		}
		$doc->set( 'redirect', $redirects );

		// Count links
		// Incoming links is the sum of:
		//  #1 Number of redirects to the page
		//  #2 Number of links to the title
		//  #3 Number of links to all the redirects
		$incomingCount = self::countIncomingLinks( $outgoingLinksToCount );

		// If there was some sort of failure counting links don't attach it to the document, instead allowing
		// whatever value is already stored to continue (rather than sending an incorrect count).
		if ( $incomingCount !== null ) {
			$doc->set( 'incoming_links', $incomingCount );
		}

		return true;
	}

	/**
	 * Count the number of incoming links to $titles. This could alternately
	 * be calculated with the BacklinkCache, but that only handles a single
	 * title at a time which is very inefficient for our use case (querying
	 * potentially hundreds of titles for counts). Instead this query gets it
	 * done in one single query which the database should be able to optimize
	 * for (and use the existing backlink index).
	 *
	 * @param Title[] $titles
	 * @return int|null The number of incoming links, or null on failure
	 */
	private static function countIncomingLinks( array $titles ) {
		$dbr = wfGetDB( DB_SLAVE );

		foreach ( $titles as $title ) {
			$conditions[] = $dbr->makeList( array(
				'pl_namespace' => $title->getNamespace(),
				'pl_title' => $title->getDBkey(),
			), LIST_AND );
		}

		$condition = $dbr->makeList( $conditions, LIST_OR );

		$res = $dbr->selectField( 'pagelinks', 'count(1)', $condition, __METHOD__ );
		if ( $res === false ) {
			return null;
		}

		return (int) $res;
	}
}
