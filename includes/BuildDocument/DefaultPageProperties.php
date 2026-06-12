<?php

namespace CirrusSearch\BuildDocument;

use CirrusSearch\Search\CirrusIndexField;
use CirrusSearch\Util;
use Elastica\Document;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Page\WikiPage;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFormatter;
use MediaWiki\Utils\MWTimestamp;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Rdbms\IReadableDatabase;

/**
 * Default properties attached to all page documents.
 */
class DefaultPageProperties implements PagePropertyBuilder {
	/** @var IReadableDatabase Wiki database to query additional page properties from. */
	private $db;

	/** @var TitleFormatter Formats namespace redirect-target text. */
	private $titleFormatter;

	/**
	 * @param IReadableDatabase $db Wiki database to query additional page properties from.
	 * @param TitleFormatter $titleFormatter Formats namespace and redirect-target text.
	 */
	public function __construct( IReadableDatabase $db, TitleFormatter $titleFormatter ) {
		$this->db = $db;
		$this->titleFormatter = $titleFormatter;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Document $doc The document to be populated
	 * @param WikiPage $page The page to scope operation to
	 */
	public function initialize( Document $doc, WikiPage $page, RevisionRecord $revision, bool $isRedirect ): void {
		$title = $page->getTitle();
		$doc->set( 'wiki', WikiMap::getCurrentWikiId() );
		$doc->set( 'page_id', $page->getId() );
		$doc->set( 'namespace',
			$title->getNamespace() );
		$doc->set( 'namespace_text',
			Util::getNamespaceText( $title, $this->titleFormatter ) );
		$doc->set( 'title', $title->getText() );
		$doc->set( 'timestamp',
			wfTimestamp( TS_ISO_8601, $revision->getTimestamp() ) );
		$createTs = $this->loadCreateTimestamp( $page->getId() );
		if ( $createTs ) {
			$doc->set( 'create_timestamp', $createTs );
		}

		// we always set redirect_target and pair that with the super_detect_noop
		// equals handler to ensure the entire map is replaced at once, clearing
		// any stale sub-field data
		if ( $isRedirect ) {
			$doc->set( 'page_type', 'redirect' );
			$content = $revision->getContent( SlotRecord::MAIN );
			$target = $content ? $content->getRedirectTarget() : null;
			$doc->set( 'redirect_target', $this->redirectTargetField( $target ) );
		} else {
			$doc->set( 'page_type', 'primary' );
			$doc->set( 'redirect_target', null );
		}
		CirrusIndexField::addNoopHandler( $doc, 'redirect_target', 'equals' );
	}

	/**
	 * Derive the redirect_target field from a redirect's resolved target.
	 *
	 * Pure mapping of the LinkTarget returned by Content::getRedirectTarget() onto
	 * the stored object. Returns null for a malformed redirect (no resolvable
	 * target); every resolvable target (same-wiki existing or broken, Special:,
	 * interwiki) yields an object.
	 *
	 * @param LinkTarget|null $target The redirect's resolved target, or null when malformed
	 * @return array|null
	 */
	public function redirectTargetField( ?LinkTarget $target ): ?array {
		if ( $target === null ) {
			return null;
		}
		return [
			// namespace may be negative (Special: = -1, Media: = -2)
			'namespace' => $target->getNamespace(),
			// getText() (namespace-less) so it is directly comparable to a document's
			// top-level title field
			'title' => $target->getText(),
			// portion after the #, empty string otherwise
			'fragment' => $target->getFragment(),
			// empty for same-wiki targets
			'interwiki' => $target->getInterwiki(),
			// full normalized target string, including any interwiki/namespace prefix
			'link' => $this->titleFormatter->getFullText( $target ),
		];
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
