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
		$title = $page->getTitle()->getPrefixedDBKey();
		CirrusSearchUpdater::updatePages( array( $page ) );
		wfDebugLog( 'CirrusSearch', "Article Saved: $title" );
		return true;
	}

	public static function articleDeleted( $page, $user, $reason, $id, $content, $logEntry ) {
		global $wgCirrusSearchUpdateInProcess;
		if ( !$wgCirrusSearchUpdateInProcess ) {
			return true;
		}
		$title = $page->getTitle()->getPrefixedDBKey();
		CirrusSearchUpdater::deleteTitles( array( $page->getTitle() ) );
		wfDebugLog( 'CirrusSearch', "Article Deleted: $title" );
		return true;
	}

	public static function articleMoved( $from, $to, $user, $pageid, $redirid ) {
		global $wgCirrusSearchUpdateInProcess;
		if ( !$wgCirrusSearchUpdateInProcess ) {
			return true;
		}
		$updates = array( WikiPage::factory( $to ) );
		if ( $redirid > 0 ) {
			$updates[] = WikiPage::factory( $from );
		} else {
			CirrusSearchUpdater::deleteTitles( array( $from ) );
		}
		CirrusSearchUpdater::updatePages( $updates );
		wfDebugLog( 'CirrusSearch', "Article Moved from $from to $to" );
		return true;
	}

	public static function updatePages( $pages ) {
		wfProfileIn( __METHOD__ );
		$client = CirrusSearch::getClient();
		$update = $client->createUpdate();
		foreach ( $pages as $page ) {
			$update->addDocument( CirrusSearchUpdater::buildDocumentforPage( $page ) );
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

	private static function buildDocumentForPage( $page ) {
		$doc = new Solarium_Document_ReadWrite();
		$doc->id = CirrusSearchUpdater::buildId( $page->getTitle() );
		$doc->title = $page->getTitle();
		$doc->text = $page->getText();
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
		if ( method_exists( $title, 'getNamespace' ) ) {
			$namespace = $title->getNamespace();
			$titleText = $title->getDBKey();
		} else {
			$namespace = $title['namespace'];
			$titleText = $title['title'];
		}
		return "$namespace:$titleText";
	}
}
