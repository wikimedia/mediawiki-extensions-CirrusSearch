<?php

namespace CirrusSearch\BuildDocument;

use CirrusSearch\Util;
use Elastica\Document;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
use MediaWiki\Utils\MWTimestamp;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Rdbms\IReadableDatabase;
use WikiPage;

/**
 * Default properties attached to all page documents.
 */
class DefaultPageProperties implements PagePropertyBuilder {
	/** @var IReadableDatabase Wiki database to query additional page properties from. */
	private $db;

	/**
	 * @param IReadableDatabase $db Wiki database to query additional page properties from.
	 */
	public function __construct( IReadableDatabase $db ) {
		$this->db = $db;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Document $doc The document to be populated
	 * @param WikiPage $page The page to scope operation to
	 */
	public function initialize( Document $doc, WikiPage $page, RevisionRecord $revision ): void {
		$title = $page->getTitle();
		$doc->set( 'wiki', WikiMap::getCurrentWikiId() );
		$doc->set( 'page_id', $page->getId() );
		$doc->set( 'namespace',
			$title->getNamespace() );
		$doc->set( 'namespace_text',
			Util::getNamespaceText( $title ) );
		$doc->set( 'title', $title->getText() );
		$doc->set( 'timestamp',
			wfTimestamp( TS_ISO_8601, $revision->getTimestamp() ) );
		$createTs = $this->loadCreateTimestamp(
			$page->getId(), TS_ISO_8601 );
		if ( $createTs !== false ) {
			$doc->set( 'create_timestamp', $createTs );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function finishInitializeBatch(): void {
		// NOOP
	}

	/**
	 * {@inheritDoc}
	 */
	public function finalize( Document $doc, Title $title, RevisionRecord $revision ): void {
		// NOOP
	}

	/**
	 * Timestamp the oldest revision of this page was created.
	 * @param int $pageId
	 * @param int $style TS_* output format constant
	 * @return string|bool Formatted timestamp or false on failure
	 */
	private function loadCreateTimestamp( int $pageId, int $style ) {
		$row = $this->db->newSelectQueryBuilder()
			->select( 'rev_timestamp' )
			->from( 'revision' )
			->where( [ 'rev_page' => $pageId ] )
			->orderBy( 'rev_timestamp' )
			->caller( __METHOD__ )
			->fetchRow();
		if ( !$row ) {
			return false;
		}
		return MWTimestamp::convert( $style, $row->rev_timestamp );
	}
}
