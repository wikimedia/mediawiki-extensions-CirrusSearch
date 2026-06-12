<?php

namespace CirrusSearch\Maintenance;

use MediaWiki\Page\WikiPage;
use MediaWiki\Title\Title;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves a single ForceSearchIndex source row to the page document(s) a reindex
 * must (re)write.
 *
 * A reindex has to reproduce the runtime indexing outcome exactly: editing a
 * redirect A -> B writes both A's own redirect document and refreshes B's
 * redirect[] array. This class owns that whole decision, so each branch is
 * exercised by unit tests with stubbed collaborators rather than stranded
 * inside the maintenance script. ForceSearchIndex only wires the collaborators
 * (an Updater for tracing, MediaWiki title validation) and feeds rows in.
 *
 * @license GPL-2.0-or-later
 */
final class IndexablePageResolver {

	/**
	 * @var callable(Title):(?WikiPage) follows a redirect chain to the page to index,
	 *  or null on a self redirect, loop, special/uncreatable page, or a target already
	 *  updated this run. Wraps Updater::traceRedirects().
	 */
	private $traceRedirect;
	/**
	 * @var callable(Title):bool false when a title cannot be rebuilt from its namespace
	 *  + text (an invalid title left in the DB). Wraps Title::makeTitleSafe().
	 */
	private $isTitleIndexable;
	/**
	 * @var True on the catch-up (date) path, false on the full reindex (id) path. A
	 *  run-level mode: only the date path traces redirects, because on the id path a
	 *  redirect's target is visited as its own row, so the redirect contributes only its
	 *  own document.
	 */
	private bool $dateBased;
	/*
	 * @var Whether redirect documents are being built
	 *  (CirrusSearchRedirectDocuments['build']). A run-level mode.
	 */
	private bool $buildRedirects;
	private Printer $printer;
	private LoggerInterface $logger;

	/**
	 * @param callable(Title):(?WikiPage) $traceRedirect follows a redirect chain to
	 *  the page to index, or null on a self redirect, loop, special/uncreatable page, or
	 *  a target already updated this run. Wraps Updater::traceRedirects().
	 * @param callable(Title):bool $isTitleIndexable false when a title cannot be rebuilt
	 *  from its namespace + text (an invalid title left in the DB). Wraps Title::makeTitleSafe().
	 * @param bool $dateBased True on the catch-up (date) path, false on the full reindex (id)
	 *  path. A run-level mode: only the date path traces redirects, because on the id path a
	 *  redirect's target is visited as its own row, so the redirect contributes only its own
	 *  document.
	 * @param bool $buildRedirects Whether redirect documents are being built
	 *  (CirrusSearchRedirectDocuments['build']). A run-level mode.
	 * @param Printer $printer Operator-facing skip notices (missing content, invalid title) are
	 *  emitted here, matching the maintenance script's console output.
	 * @param LoggerInterface $logger Content deserialization failures are logged here.
	 */
	public function __construct(
		callable $traceRedirect,
		callable $isTitleIndexable,
		bool $dateBased,
		bool $buildRedirects,
		Printer $printer,
		LoggerInterface $logger
	) {
		$this->traceRedirect = $traceRedirect;
		$this->isTitleIndexable = $isTitleIndexable;
		$this->dateBased = $dateBased;
		$this->buildRedirects = $buildRedirects;
		$this->printer = $printer;
		$this->logger = $logger;
	}

	/**
	 * Resolve a source row's page to the 0, 1, or 2 pages to hand the Updater.
	 *
	 * @param WikiPage $page The source row's page (redirect or not).
	 * @return WikiPage[] Pages to index for this row (0, 1, or 2).
	 */
	public function resolve( WikiPage $page ): array {
		try {
			$content = $page->getContent();
		} catch ( Throwable ) {
			$this->logger->warning(
				"Error deserializing content, skipping page: {pageId}",
				[ 'pageId' => $page->getTitle()->getArticleID() ]
			);
			return [];
		}

		if ( $content === null ) {
			// No content because the latest revision loaded by the source query doesn't exist.
			$this->printer->output(
				'Skipping page with no content: ' . $page->getTitle()->getArticleID() . "\n"
			);
			return [];
		}

		$isRedirect = $content->isRedirect();

		// Only the date-based path traces redirects (see $this->dateBased). traceRedirects is
		// "very complete": it returns null on self redirects, loops, and special/uncreatable
		// pages, so a null target here means there is simply nothing to refresh.
		$tracedTarget = null;
		if ( $isRedirect && $this->dateBased ) {
			$tracedTarget = ( $this->traceRedirect )( $page->getTitle() );
			if ( $tracedTarget !== null
				&& !( $this->isTitleIndexable )( $tracedTarget->getTitle() )
			) {
				// An invalid title left in the DB that can't be rebuilt from its ns + text.
				// These are hardly viewable, so don't refresh them. Keep $page so the redirect
				// can still emit its own document under build:true.
				$this->printer->output(
					'Skipping page with invalid title: ' . $tracedTarget->getTitle()->getPrefixedText()
				);
				$tracedTarget = null;
			}
		}

		$pages = [];

		// Piece 1 -- index the row's own page as a document. A redirect is a document of
		// its own only when redirect documents are being built.  This is the whole of the
		// normal (id-based) new-index population path.
		if ( !$isRedirect || $this->buildRedirects ) {
			$pages[] = $page;
		}

		// Piece 2 -- date backfill parity: a redirect edit also refreshes its target's
		// redirect[] array, mirroring the real-time LinksUpdate.  $tracedTarget is null
		// on the id-based path and whenever the chain cannot be resolved (self redirect,
		// loop, special page, or invalid title), in which case there is nothing to
		// refresh.
		if ( $tracedTarget !== null ) {
			$pages[] = $tracedTarget;
		}

		return $pages;
	}
}
