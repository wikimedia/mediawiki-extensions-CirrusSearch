<?php

namespace CirrusSearch\BuildDocument;

use CirrusSearch\Util;
use Elastica\Document;
use MediaWiki\Page\WikiPage;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;
use MediaWiki\Utils\MWTimestamp;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Rdbms\IReadableDatabase;

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
		$createTs = $this->loadCreateTimestamp( $page->getId() );
		if ( $createTs ) {
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
	 */
	private function loadCreateTimestamp( int $pageId ): ?string {
		$createTs = $this->db->newSelectQueryBuilder()
			->select( 'MIN(rev_timestamp)' )
			->from( 'revision' )
			->where( [ 'rev_page' => $pageId ] )
			->caller( __METHOD__ )
			->fetchField();
		if ( $createTs ) {
			$createTs = MWTimestamp::convert( TS_ISO_8601, $createTs );
		}
		return $createTs ?: null;
	}
}
