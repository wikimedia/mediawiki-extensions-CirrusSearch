<?php

$mwSearchUpdateHost = 'localhost';
$mwSearchUpdatePort = 8124;
$mwSearchUpdateDebug = false;

class SolrSearchUpdater {
	/**
	 * @param $article WikiPage the saved page
	 */
	static function articleSaved( $page, $user, $text, $summary, $isminor, $iswatch, $section ) {
		$id = $article->getId();
		$title = $article->getTitle();
		wfDebugLog( 'SolrSearch', "Article Saved: $id($title) with $text" );
		SolrSearchUpdater::updatePages( array( $page ) );
		return true;
	}
	static function articleDeleted( $article, $user, $reason ) {
		$title = $article->getTitle();
		wfDebugLog( 'SolrSearch', "Article Deleted: $title" );
		return true;	
	}
	static function articleMoved( $from, $to, $user, $pageid, $redirid ) {
		$title = $article->getTitle();
		wfDebugLog( 'SolrSearch', "Article Moved from $from to $to" );
		return true;
	}

	static public function updatePages( $pages ) {
		wfProfileIn( __METHOD__ );
		$update = SolrSearch::getClient()->createUpdate();
		foreach ( $pages as $page ) {
			$update->addDocument(SolrSearchUpdater::buildDocumentforPage( $page ) );
		}
		try {
			$result = SolrSearch::getClient()->update( $update );
			wfDebugLog( 'SolrSearch', 'Update completed in ' . $result->getQueryTime() . ' millis and has status ' . $result->getStatus() );
		} catch ( Solarium_Exception $e ) {
			error_log( "SolrSearch update failed for $id caused by:  " . $e->getMessage() );
		}
		wfProfileOut( __METHOD__ );
		return true;
	}

	static private function buildDocumentForPage( $page ) {
		$doc = new Solarium_Document_ReadWrite();
		$doc->id = $page->getId();
		$doc->title = $page->getTitle();
		$doc->text = $page->getText();
		return $doc;
	}
}
