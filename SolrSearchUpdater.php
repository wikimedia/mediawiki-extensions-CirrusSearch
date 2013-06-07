<?php

$mwSearchUpdateHost = 'localhost';
$mwSearchUpdatePort = 8124;
$mwSearchUpdateDebug = false;

class SolrSearchUpdater {
	/**
	 * @param $article WikiPage the saved page
	 */
	static function articleSaved( $article, $user, $text, $summary, $isminor, $iswatch, $section ) {
		$id = $article->getId();
		$title = $article->getTitle();
		wfDebugLog( 'SolrSearch', "Article Saved: $id($title) with $text" );
		$updater = new SolrSearchUpdater();
		$updater->updateArticle( $id, $title, $text );
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

	private function updateArticle( $id, $title, $text) {
		wfProfileIn( __METHOD__ );
		$update = SolrSearch::getClient()->createUpdate();
		$update->addDocument(SolrSearchUpdater::buildDocumentforArticle( $id, $title, $text ) );
		try {
			$result = SolrSearch::getClient()->update( $update );
			wfDebugLog( 'SolrSearch', 'Update completed in ' . $result->getQueryTime() . ' millis and has status ' . $result->getStatus() );
		} catch ( Solarium_Exception $e ) {
			error_log( "SolrSearch update failed for $id caused by:  " . $e->getMessage() );
		}
		wfProfileIn( __METHOD__ );
		return true;
	}

	private function buildDocumentForArticle( $id, $title, $text ) {
		wfDebugLog( 'SolrSearch', "Building document for $id" );
		$doc = new Solarium_Document_ReadWrite();
		$doc->id = $id;
		$doc->title = $title;
		$doc->text = $text;
		return $doc;
	}
}
