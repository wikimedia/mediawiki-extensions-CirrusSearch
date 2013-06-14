<?php

class CirrusSearchUpdater {
	/**
	 * @param $article WikiPage the saved page
	 */
	public static function articleSaved( $page, $user, $text, $summary, $isminor, $iswatch, $section ) {
		global $wgCirrusSearchUpdateInProcess;
		if ( !$wgCirrusSearchUpdateInProcess ) {
			return true;
		}
		wfProfileIn( __METHOD__ );
		$title = $page->getTitle()->getPrefixedDBKey();
		CirrusSearchUpdater::updateRevisions( array( $page->getRevision() ) );
		wfDebugLog( 'CirrusSearch', "Article Saved: $title" );
		wfProfileOut( __METHOD__ );
		return true;
	}

	public static function articleDeleted( $page, $user, $reason, $id, $content, $logEntry ) {
		global $wgCirrusSearchUpdateInProcess;
		if ( !$wgCirrusSearchUpdateInProcess ) {
			return true;
		}
		wfProfileIn( __METHOD__ );
		$title = $page->getTitle()->getPrefixedDBKey();
		CirrusSearchUpdater::deleteTitles( array( $page->getTitle() ) );
		wfDebugLog( 'CirrusSearch', "Article Deleted: $title" );
		wfProfileOut( __METHOD__ );
		return true;
	}

	public static function articleMoved( $from, $to, $user, $pageid, $redirid ) {
		global $wgCirrusSearchUpdateInProcess;
		if ( !$wgCirrusSearchUpdateInProcess ) {
			return true;
		}
		wfProfileIn( __METHOD__ );
		$updates = array( WikiPage::factory( $to )->getRevision() );
		if ( $redirid > 0 ) {
			$updates[] = WikiPage::factory( $from )->getRevision();
		} else {
			CirrusSearchUpdater::deleteTitles( array( $from ) );
		}
		CirrusSearchUpdater::updateRevisions( $updates );
		wfDebugLog( 'CirrusSearch', "Article Moved from $from to $to" );
		wfProfileOut( __METHOD__ );
		return true;
	}

	public static function updateRevisions( $revisions ) {
		wfProfileIn( __METHOD__ );
		$client = CirrusSearch::getClient();
		$update = $client->createUpdate();
		foreach ( $revisions as $revision ) {
			$update->addDocument( CirrusSearchUpdater::buildDocumentforRevision( $revision ) );
		}
		try {
			$result = $client->update( $update );
			wfDebugLog( 'CirrusSearch', 'Update completed in ' . $result->getQueryTime() . ' millis and has status ' . $result->getStatus() );
		} catch ( Solarium_Exception $e ) {
			error_log( "CirrusSearch update failed caused by:  " . $e->getMessage() );
		}
		wfProfileOut( __METHOD__ );
		return true;
	}

	private static function buildDocumentforRevision( $revision ) {
		wfProfileIn( __METHOD__ );
		$doc = new Solarium_Document_ReadWrite();
		$doc->id = CirrusSearchUpdater::buildId( $revision->getTitle() );
		$doc->title = $revision->getTitle()->getText();
		$doc->text = $revision->getContent()->getTextForSearchIndex();
		wfProfileOut( __METHOD__ );
		return $doc;
	}

	public static function deleteTitles( $titles ) {
		wfProfileIn( __METHOD__ );
		$client = CirrusSearch::getClient();
		$update = $client->createUpdate();
		foreach ( $titles as $title ) {
			$update->addDeleteById( CirrusSearchUpdater::buildId( $title ) );
		}
		$update->addCommit();
		try {
			$result = $client->update( $update );
			wfDebugLog( 'CirrusSearch', 'Delete completed in ' . $result->getQueryTime() . ' millis and has status ' . $result->getStatus() );
		} catch ( Solarium_Exception $e ) {
			error_log( "CirrusSearch delete failed caused by:  " . $e->getMessage() );
		}
		wfProfileOut( __METHOD__ );
		return true;
	}

	/**
	 * Build the id for a title.
	 * @param $page Either a title or an array with 'namespace' and 'title' keys. 
	 */
	private static function buildId( $title ) {
		wfProfileIn( __METHOD__ );
		if ( method_exists( $title, 'getNamespace' ) ) {
			$namespace = $title->getNamespace();
			$titleText = $title->getDBKey();
		} else {
			$namespace = $title['namespace'];
			$titleText = $title['title'];
		}
		wfProfileOut( __METHOD__ );
		return "$namespace:$titleText";
	}
}
