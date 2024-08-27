<?php

namespace CirrusSearch\Sanity;

use MediaWiki\Title\Title;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use WikiPage;

/**
 * {@link Remediator} that only logs events.
 *
 * Intended to visualize deviations without remediating them,
 * in case CirrusSearch is not in charge of writing.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
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
