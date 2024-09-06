<?php

namespace CirrusSearch\Search;

use CirrusSearch\Searcher;
use CirrusSearch\Util;
use File;
use LogicException;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use SearchResult;
use SearchResultTrait;

/**
 * Base class for SearchResult
 */
abstract class CirrusSearchResult extends SearchResult {
	use SearchResultTrait;

	/**
	 * @var string Counter title for identified missing revisions
	 */
	private const MISSING_REVISION_TOTAL = 'missing_revision_total';

	/**
	 * @var Title
	 */
	private $title;

	/**
	 * @var ?File
	 */
	private $file;

	/**
	 * @var bool
	 */
	private $checkedForFile = false;

	/**
	 * @param Title $title
	 */
	public function __construct( Title $title ) {
		$this->title = $title;
	}

	/**
	 * Initialize from a Title and if possible initializes a corresponding
	 * File.
	 *
	 * @param Title $title
	 */
	final protected function initFromTitle( $title ) {
		// Everything is done in the constructor.
		// XXX: we do not call the SearchResultInitFromTitle hook
		// this hook is designed to fetch a particular revision
		// but the way cirrus works does not allow to vary the revision
		// text being displayed at query time.
	}

	/**
	 * Check if this is result points to an invalid title
	 *
	 * @return bool
	 */
	final public function isBrokenTitle() {
		// Title is mandatory in the constructor it would have failed earlier if the Title was broken
		return false;
	}

	/**
	 * Check if target page is missing, happens when index is out of date
	 *
	 * @return bool
	 */
	final public function isMissingRevision() {
		global $wgCirrusSearchDevelOptions;
		if ( isset( $wgCirrusSearchDevelOptions['ignore_missing_rev'] ) ) {
			return false;
		}
		if ( !$this->getTitle()->isKnown() ) {
			$this->increment( self::MISSING_REVISION_TOTAL, 'title' );
			return true;
		}
		// Similarly if we matched due to a redirect
		if ( $this->getRedirectTitle() && !$this->getRedirectTitle()->isKnown() ) {
			// There may be other reasons this result matched, for now keep it in the results
			// but clear the redirect.
			$redirectIsOnlyMatch = $this->clearRedirectTitle();
			$this->increment(
				self::MISSING_REVISION_TOTAL,
				$redirectIsOnlyMatch ? 'only_redirect' : 'redirect'
			);
		}

		return false;
	}

	private function increment( string $counter, string $problem ) {
		Util::getStatsFactory()
			->getCounter( $counter )
			->setLabel( 'problem', $problem )
			->increment();
	}

	/**
	 * @return Title
	 */
	final public function getTitle() {
		return $this->title;
	}

	/**
	 * Get the file for this page, if one exists
	 * @return File|null
	 */
	final public function getFile() {
		if ( !$this->checkedForFile && $this->getTitle()->getNamespace() === NS_FILE ) {
			$this->checkedForFile = true;
			$this->file = MediaWikiServices::getInstance()->getRepoGroup()
				->findFile( $this->title );
		}
		return $this->file;
	}

	/**
	 * Lazy initialization of article text from DB
	 * @return never
	 */
	final protected function initText() {
		throw new LogicException( "initText() should not be called on CirrusSearchResult, " .
			"content must be fetched directly from the backend at query time." );
	}

	/**
	 * @param string $text A snippet from the search highlighter
	 * @return bool True when the string contains highlight markers
	 */
	protected function containsHighlight( string $text ): bool {
		return strpos( $text, Searcher::HIGHLIGHT_PRE ) !== false;
	}

	/**
	 * @return string
	 */
	abstract public function getDocId();

	/**
	 * @return float
	 */
	abstract public function getScore();

	/**
	 * @return array|null
	 */
	abstract public function getExplanation();

	/**
	 * Clear any redirect match so it won't be part of the result.
	 *
	 * @return bool True if the redirect was the only snippet available
	 *  for this result.
	 */
	abstract protected function clearRedirectTitle(): bool;
}
