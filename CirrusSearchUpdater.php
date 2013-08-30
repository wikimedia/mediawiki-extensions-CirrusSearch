<?php
/**
 * Performs updates and deletes on the Elasticsearch index.  Called by
 * CirrusSearch.body.php (our SearchEngine implementation), forceSearchIndex
 * (for bulk updates), and hooked into LinksUpdate (for implied updates).
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
class CirrusSearchUpdater {
	/**
	 * Regex to remove text we don't want to search but that isn't already
	 * removed when stripping HTML or the toc.
	 */
	const SANITIZE = '/
		<video .*?<\/video>  # remove the sorry, not supported message
	/x';

	/**
	 * Article IDs updated in this process.  Used for deduplication of updates.
	 * @var array(Integer)
	 */
	private static $updated = array();

	/**
	 * Update a single article using its Title and pre-sanitized text.
	 */
	public static function updateFromTitleAndText( $id, $title, $text ) {
		if ( in_array( $id, self::$updated ) ) {
			// Already indexed $id
			return;
		}
		$revision = Revision::loadFromPageId( wfGetDB( DB_SLAVE ), $id );
		$content = $revision->getContent();
		if ( $content->isRedirect() ) {
			$target = $content->getUltimateRedirectTarget();
			wfDebugLog( 'CirrusSearch', "Updating search index for $title which is a redirect to " . $target->getText() );
			self::updateFromTitle( $target );
		} else {
			self::updateRevisions( array( array(
				'rev' => $revision,
				'text' => $text,
			) ) );
			self::$updated[] = $id;
		}
	}

	/**
	 * Performs an update when the $text is not already available by firing the
	 * update back through the SearchEngine to get the sanitized text.
	 * @param Title $title
	 */
	public static function updateFromTitle( $title ) {
		$articleId = $title->getArticleID();
		$revision = Revision::loadFromPageId( wfGetDB( DB_SLAVE ), $articleId );

		// This usually happens on page creation when all the revision data hasn't
		// replicated out to the slaves
		if ( !$revision ) {
			$revision = Revision::loadFromPageId( wfGetDB( DB_MASTER ), $articleId );
			// This usually happens when building a redirect to a non-existant page
			if ( !$revision ) {
				return;
			}
		}

		$update = new SearchUpdate( $articleId, $title, $revision->getContent() );
		$update->doUpdate();
	}

	/**
	 * Hooked to update the search index for pages when templates that they include are changed
	 * and to kick off updating linked articles.
	 * @param $linksUpdate LinksUpdate
	 */
	public static function linksUpdateCompletedHook( $linksUpdate ) {
		self::updateFromTitle( $linksUpdate->getTitle() );
		self::updateLinkedArticles( $linksUpdate );
	}

	/**
	 * This updates pages in elasticsearch.
	 *
	 * @param array $pageData An array of revisions and their pre-processed
	 * data. The format is as follows:
	 *   array(
	 *     array(
	 *       'rev' => current revision object
	 *       'text' => text of the current page
	 *     )
	 *   )
	 */
	public static function updateRevisions( $pageData ) {
		wfProfileIn( __METHOD__ );

		$contentDocuments = array();
		$generalDocuments = array();
		foreach ( $pageData as $page ) {
			$document = self::buildDocumentforRevision( $page );
			if ( MWNamespace::isContent( $document->get( 'namespace' ) ) ) {
				$contentDocuments[] = $document;
			} else {
				$generalDocuments[] = $document;
			}
		}
		self::sendDocuments( CirrusSearchConnection::CONTENT_INDEX_TYPE, $contentDocuments );
		self::sendDocuments( CirrusSearchConnection::GENERAL_INDEX_TYPE, $generalDocuments );

		wfProfileOut( __METHOD__ );
	}

	/**
	 * Delete pages from the elasticsearch index
	 *
	 * @param array $pages An array of ids to delete
	 */
	public static function deletePages( $pages ) {
		wfProfileIn( __METHOD__ );

		self::sendDeletes( CirrusSearchConnection::CONTENT_INDEX_TYPE, $pages );
		self::sendDeletes( CirrusSearchConnection::GENERAL_INDEX_TYPE, $pages );

		wfProfileOut( __METHOD__ );
	}

	/**
	 * @param $indexType
	 * @param $documents array
	 */
	private static function sendDocuments( $indexType, $documents ) {
		wfProfileIn( __METHOD__ );

		$documentCount = count( $documents );
		if ( $documentCount === 0 ) {
			return;
		}
		wfDebugLog( 'CirrusSearch', "Sending $documentCount documents to the $indexType index." );
		$work = new PoolCounterWorkViaCallback( 'CirrusSearch-Update', "_elasticsearch",
			array( 'doWork' => function() use ( $indexType, $documents ) {
				try {
					$result = CirrusSearchConnection::getPageType( $indexType )->addDocuments( $documents );
					wfDebugLog( 'CirrusSearch', 'Update completed in ' . $result->getEngineTime() . ' (engine) millis' );
				} catch ( \Elastica\Exception\ExceptionInterface $e ) {
					error_log( "CirrusSearch update failed caused by:  " . $e->getMessage() );
				}
			}
		) );
		$work->execute();
		wfProfileOut( __METHOD__ );
	}

	private static function buildDocumentforRevision( $page ) {
		global $wgCirrusSearchIndexedRedirects;
		wfProfileIn( __METHOD__ );
		$revision = $page[ 'rev' ];
		$text = $page[ 'text' ];
		$title = $revision->getTitle();
		$article = new Article( $title, $revision->getId() );
		$parserOutput = $article->getParserOutput( $revision->getId() );

		$categories = array();
		foreach ( $parserOutput->getCategories() as $key => $value ) {
			$category = Category::newFromName( $key );
			$categories[] = $category->getTitle()->getText();
		}

		$backlinkCache = new BacklinkCache( $title );
		$links = $backlinkCache->getNumLinks( 'pagelinks' );

		// Handle redirects to this page
		$redirectTitles = $backlinkCache->getLinks( 'redirect', false, false, $wgCirrusSearchIndexedRedirects );
		$redirects = array();
		$redirectLinks = 0;
		foreach ( $redirectTitles as $redirect ) {
			// If the redirect is in main or the same namespace as the article the index it
			if ( $redirect->getNamespace() === NS_MAIN && $redirect->getNamespace() === $title->getNamespace()) {
				$redirects[] = array(
					'namespace' => $redirect->getNamespace(),
					'title' => $redirect->getText()
				);
			}
			// Count links to redirects
			// Note that we don't count redirect to redirects here because that seems a bit much.
			$redirectBacklinkCache = new Backlinkcache( $redirect );
			$redirectLinks += $redirectBacklinkCache->getNumLinks( 'pagelinks' );
		}

		$doc = new \Elastica\Document( $revision->getPage(), array(
			'namespace' => $title->getNamespace(),
			'title' => $title->getText(),
			'text' => Sanitizer::stripAllTags( $text ),
			'textLen' => $revision->getSize(),
			'timestamp' => wfTimestamp( TS_ISO_8601, $revision->getTimestamp() ),
			'category' => $categories,
			'redirect' => $redirects,
			'links' => $links,
			'redirect_links' => $redirectLinks,
		) );

		wfProfileOut( __METHOD__ );
		return $doc;
	}

	/**
	 * Update the search index for articles linked from this article.
	 * @param $linksUpdate LinksUpdate
	 */
	private static function updateLinkedArticles( $linksUpdate ) {
		// This could be made more efficient by having LinksUpdate return a list of articles who
		// have been newly linked or newly unlinked.  Those are the only articles that we need
		// to reindex any way.

		// This could also be made more efficient by only updating the link counts rather than
		// reindexing the whole article.
		global $wgCirrusSearchLinkedArticlesToUpdate;

		// Build a big list of candidate pages who's links we should update
		$candidates = array();
		foreach ( $linksUpdate->getParserOutput()->getLinks() as $ns => $ids ) {
			foreach ( $ids as $id ) {
				$candidates[] = $id;
			}
		}

		// Pick up to $wgCirrusSearchLinkedArticlesToUpdate links to update
		$chosenCount = min( count( $candidates ), $wgCirrusSearchLinkedArticlesToUpdate );
		if ( $chosenCount < 1 ) {
			return;
		}
		$chosen = array_rand( $candidates, $chosenCount );
		// array_rand is $chosenCount === 1 then array_rand will return a key rather than an
		// array of keys so just wrap the key and move on with the rest of the request.
		if ( !is_array( $chosen ) ) {
			$chosen = array( $chosen );
		}
		foreach ( $chosen as $key ) {
			$title = Title::newFromID( $candidates[ $key ] );
			// Skip links to non-existant pages.
			if ( $title === null ) {
				continue;
			}
			wfDebugLog( 'CirrusSearch', "Updating $title because it was linked." );
			self::updateFromTitle( $title );
		}
	}

	/**
	 * @param $indexType
	 * @param $ids array
	 */
	private static function sendDeletes( $indexType, $ids ) {
		wfProfileIn( __METHOD__ );

		$idCount = count( $ids );
		if ( $idCount === 0 ) {
			return;
		}
		wfDebugLog( 'CirrusSearch', "Sending $idCount deletes to the $indexType index." );
		$work = new PoolCounterWorkViaCallback( 'CirrusSearch-Update', "_elasticsearch",
			array( 'doWork' => function() use ( $indexType, $ids ) {
				try {
					$result = CirrusSearchConnection::getPageType( $indexType )->deleteIds( $ids );
					wfDebugLog( 'CirrusSearch', 'Delete completed in ' . $result->getEngineTime() . ' (engine) millis' );
				} catch ( \Elastica\Exception\ExceptionInterface $e ) {
					error_log( "CirrusSearch delete failed caused by:  " . $e->getMessage() );
				}
			}
		) );
		$work->execute();
		wfProfileOut( __METHOD__ );
	}
}
