<?php

namespace CirrusSearch\BuildDocument;

use Elastica\Document;
use MediaWiki\Page\WikiPage;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;

/**
 * Interface for building subsets of the document stored in elasticsearch
 * to represent individual wiki pages.
 */
interface PagePropertyBuilder {
	/**
	 * Perform initial building of a page document.
	 *
	 * Called once per page when starting an update and is shared between all
	 * clusters written to. This doc may be written to the jobqueue multiple
	 * times and should not contain any large (in number of bytes) values.
	 *
	 * @param Document $doc The document to be populated
	 * @param WikiPage $page The page to scope operation to
	 * @param RevisionRecord $revision The page revision to use
	 * @param bool $isRedirect Whether $page is a redirect, as already resolved by the
	 *  orchestrator from the same source as the rest of the build (avoids re-deriving it,
	 *  so page_type and redirect_target cannot disagree on the revision path)
	 */
	public function initialize( Document $doc, WikiPage $page, RevisionRecord $revision, bool $isRedirect ): void;

	/**
	 * Called after a batch of pages have been passed to self::initialize.
	 *
	 * Allows implementations to batch calls to external services necessary for
	 * collecting page properties. Implementations must update the Document
	 * instances previously provided.
	 *
	 * The builder will be disposed of after finishing a batch.
	 */
	public function finishInitializeBatch(): void;

	/**
	 * Finalize document building before sending to cluster.
	 *
	 * Called on every write attempt for every cluster to perform any final
	 * document building.  Intended for bulk loading of content from wiki
	 * databases that would only serve to bloat the job queue.
	 *
	 * @param Document $doc
	 * @param Title $title
	 * @param RevisionRecord $revision
	 * @throws BuildDocumentException
	 */
	public function finalize( Document $doc, Title $title, RevisionRecord $revision ): void;
}
