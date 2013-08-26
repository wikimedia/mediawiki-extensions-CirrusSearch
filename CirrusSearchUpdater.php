<?php
/**
 * Wrapper around the update/delete mechanisms within elasticsearch
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
			$document = CirrusSearchUpdater::buildDocumentforRevision( $page );
			if ( MWNamespace::isContent( $document->get( 'namespace' ) ) ) {
				$contentDocuments[] = $document;
			} else {
				$generalDocuments[] = $document;
			}
		}
		CirrusSearchUpdater::sendDocuments( CirrusSearch::CONTENT_INDEX_TYPE, $contentDocuments );
		CirrusSearchUpdater::sendDocuments( CirrusSearch::GENERAL_INDEX_TYPE, $generalDocuments );

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
					$result = CirrusSearch::getPageType( $indexType )->addDocuments( $documents );
					wfDebugLog( 'CirrusSearch', 'Update completed in ' . $result->getEngineTime() . ' (engine) millis' );
				} catch ( \Elastica\Exception\ExceptionInterface $e ) {
					error_log( "CirrusSearch update failed caused by:  " . $e->getMessage() );
				}
			}
		) );
		$work->execute();
		wfProfileOut( __METHOD__ );
	}

	public static function buildDocumentforRevision( $page ) {
		global $wgCirrusSearchIndexedRedirects;
		wfProfileIn( __METHOD__ );
		$revision = $page[ 'rev' ];
		$text = $page[ 'text' ];
		$title = $revision->getTitle();
		$article = new Article( $title, $revision->getId() );
		$parserOutput = $article->getParserOutput( $revision->getId() );

		$categories = array();
		foreach ( $parserOutput->getCategories() as $key => $value ) {
			$categories[] = $key;
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
	 * Delete pages from the elasticsearch index
	 *
	 * @param array $pages An array of ids to delete
	 */
	public static function deletePages( $pages ) {
		wfProfileIn( __METHOD__ );

		CirrusSearchUpdater::sendDeletes( CirrusSearch::CONTENT_INDEX_TYPE, $pages );
		CirrusSearchUpdater::sendDeletes( CirrusSearch::GENERAL_INDEX_TYPE, $pages );

		wfProfileOut( __METHOD__ );
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
					$result = CirrusSearch::getPageType( $indexType )->deleteIds( $ids );
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
