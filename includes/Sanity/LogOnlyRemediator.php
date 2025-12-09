<?php

namespace CirrusSearch\Sanity;

use MediaWiki\Page\WikiPage;
use MediaWiki\Title\Title;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * {@link Remediator} that only logs events.
 *
 * Intended to visualize deviations without remediating them,
 * in case CirrusSearch is not in charge of writing.
 *
 * @license GPL-2.0-or-later
 */
class LogOnlyRemediator implements Remediator {

	private LoggerInterface $logger;
	private string $level;
	private string $prefix;

	public function __construct( LoggerInterface $logger, string $level = LogLevel::INFO ) {
		$this->logger = $logger;
		$this->level = $level;
		$this->prefix = str_replace( __NAMESPACE__ . '\\', '', __CLASS__ );
	}

	/**
	 * @inheritDoc
	 */
	public function redirectInIndex( string $docId, WikiPage $page, string $indexSuffix ) {
		$this->logger->log( $this->level, $this->prefix . '::' . __FUNCTION__,
			[ 'page' => $page->getId(), 'indexSuffix' => $indexSuffix, 'title' => $page->getTitle()->getPrefixedText() ] );
	}

	/**
	 * @inheritDoc
	 */
	public function pageNotInIndex( WikiPage $page ) {
		$this->logger->log( $this->level, $this->prefix . '::' . __FUNCTION__,
			[ 'page' => $page->getId(), 'title' => $page->getTitle()->getPrefixedText() ] );
	}

	/**
	 * @inheritDoc
	 */
	public function ghostPageInIndex( $docId, Title $title ) {
		$this->logger->log( $this->level, $this->prefix . '::' . __FUNCTION__,
			[ 'doc' => $docId, 'title' => $title->getPrefixedText() ] );
	}

	/**
	 * @inheritDoc
	 */
	public function pageInWrongIndex( $docId, WikiPage $page, $indexSuffix ) {
		$this->logger->log( $this->level, $this->prefix . '::' . __FUNCTION__,
			[ 'doc' => $docId, 'page' => $page->getId(), 'title' => $page->getTitle()->getPrefixedText(), 'indexSuffix' => $indexSuffix ] );
	}

	/**
	 * @inheritDoc
	 */
	public function oldVersionInIndex( $docId, WikiPage $page, $indexSuffix ) {
		$this->logger->log( $this->level, $this->prefix . '::' . __FUNCTION__,
			[ 'doc' => $docId, 'page' => $page->getId(), 'title' => $page->getTitle()->getPrefixedText(), 'indexSuffix' => $indexSuffix ] );
	}

	/**
	 * @inheritDoc
	 */
	public function oldDocument( WikiPage $page ) {
		// do not log, see https://gerrit.wikimedia.org/r/c/mediawiki/extensions/CirrusSearch/+/991599/comments/33ecb273_74895cf2
	}

}
