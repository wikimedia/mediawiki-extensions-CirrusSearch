<?php
/**
 * Wrapper around the update/delete mechanisms within Solr
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
	 * This updates pages in Solr.
	 *
	 * @param array $pageData An array of revisions and their pre-processed
	 * data. The format is as follows:
	 *   array( array( 'rev' => $revision, 'text' => $text ), ... )
	 */
	public static function updateRevisions( $pageData ) {
		wfProfileIn( __METHOD__ );

		$client = CirrusSearch::getClient();
		$host = $client->getAdapter()->getHost();
		$work = new PoolCounterWorkViaCallback( 'CirrusSearch-Update', "_solr:host:$host",
			array( 'doWork' => function() use ( $client, $pageData ) {
				$update = $client->createUpdate();
				foreach ( $pageData as $page ) {
					// @todo When $text is null, we only want to update the title, not the whole document
					$update->addDocument( CirrusSearchUpdater::buildDocumentforRevision( $page['rev'], $page['text'] ) );
				}
				try {
					$result = $client->update( $update );
					wfDebugLog( 'CirrusSearch', 'Update completed in ' . $result->getQueryTime() . ' millis and has status ' . $result->getStatus() );
				} catch ( Solarium_Exception $e ) {
					error_log( "CirrusSearch update failed caused by:  " . $e->getMessage() );
				}
			}
		) );
		$work->execute();

		wfProfileOut( __METHOD__ );
	}

	public static function buildDocumentforRevision( $revision, $text ) {
		wfProfileIn( __METHOD__ );
		$title = $revision->getTitle();
		$content = $revision->getContent();
		$parserOutput = $content->getParserOutput( $title, null, null, false );

		$doc = new Solarium_Document_ReadWrite();
		$doc->id = $revision->getPage();
		$doc->namespace = $title->getNamespace();
		$doc->title = $title->getText();
		$doc->text = $text;
		$doc->textLen = $revision->getSize();
		$doc->timestamp = wfTimestamp( TS_ISO_8601, $revision->getTimestamp() );
		// TODO this seems aweful hacky
		foreach ( $parserOutput->getCategories() as $key => $value ) {
			$doc->addField( 'category', $key );
		}

		wfProfileOut( __METHOD__ );
		return $doc;
	}

	/**
	 * Delete pages from the Solr index
	 *
	 * @param array $pageIds An array of page ids to delete from the index
	 */
	public static function deletePages( $pageIds ) {
		wfProfileIn( __METHOD__ );

		$client = CirrusSearch::getClient();
		$host = $client->getAdapter()->getHost();
		$work = new PoolCounterWorkViaCallback( 'CirrusSearch-Delete', "_solr:host:$host",
			array( 'doWork' => function() use ( $client ) {
				$client = CirrusSearch::getClient();
				$update = $client->createUpdate();
				foreach ( $pageIds as $pid ) {
					$update->addDeleteById( $pid );
				}
				$update->addCommit();
				try {
					$result = $client->update( $update );
					wfDebugLog( 'CirrusSearch', 'Delete completed in ' . $result->getQueryTime() . ' millis and has status ' . $result->getStatus() );
				} catch ( Solarium_Exception $e ) {
					error_log( "CirrusSearch delete failed caused by:  " . $e->getMessage() );
				}
			}
		) );

		$work->execute();

		wfProfileOut( __METHOD__ );
	}
}
