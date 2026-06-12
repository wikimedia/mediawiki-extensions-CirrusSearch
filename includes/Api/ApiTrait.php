<?php

namespace CirrusSearch\Api;

use CirrusSearch\Connection;
use CirrusSearch\SearchConfig;
use CirrusSearch\Searcher;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

trait ApiTrait {
	/** @var Connection */
	private $connection;
	/** @var SearchConfig */
	private $searchConfig;

	/**
	 * @return Connection
	 */
	public function getCirrusConnection() {
		if ( $this->connection === null ) {
			$this->connection = new Connection( $this->getSearchConfig() );
		}
		return $this->connection;
	}

	/**
	 * @return SearchConfig
	 */
	protected function getSearchConfig() {
		if ( $this->searchConfig === null ) {
			// @phan-suppress-next-line PhanTypeMismatchProperty
			$this->searchConfig = MediaWikiServices::getInstance()
				->getConfigFactory()
				->makeConfig( 'CirrusSearch' );
		}
		return $this->searchConfig;
	}

	/**
	 * @param PageIdentity $title
	 * @param string[]|bool $sourceFiltering source filtering to apply
	 * @param bool $followRedirects When false, return the requested page's own indexed
	 *  document instead of tracing redirects to the target (redirect scope). Lets callers
	 *  inspect a redirect's first-class document.
	 * @return array
	 */
	public function loadDocuments( PageIdentity $title, $sourceFiltering = true, bool $followRedirects = true ) {
		[ $docId, $hasRedirects ] = $this->determineCirrusDocId( $title, $followRedirects );
		if ( $docId === null ) {
			return [];
		}
		$title = Title::newFromPageIdentity( $title );
		// could be optimized by implementing multi-get but not
		// expecting much usage except debugging/tests.
		$searcher = new Searcher( $this->getCirrusConnection(), 0, 0, $this->getSearchConfig(), [], $this->getUser() );
		$esSources = $searcher->get( [ $docId ], $sourceFiltering );
		$result = [];
		if ( $esSources->isOK() ) {
			foreach ( $esSources->getValue() as $esSource ) {
				// If we have followed redirects only report the
				// article dump if the redirect has been indexed. If it
				// hasn't been indexed this document does not represent
				// the original title.
				if ( $hasRedirects &&
					 !$this->hasRedirect( $esSource->getData(), $title )
				) {
					continue;
				}

				// If this was not a redirect and the title doesn't match that
				// means a page was moved, but elasticsearch has not yet been
				// updated. Don't return the document that doesn't actually
				// represent the page (yet).
				if ( !$hasRedirects && $esSource->getData()['title'] != $title->getText() ) {
					continue;
				}

				$result[] = [
					'index' => $esSource->getIndex(),
					'type' => $esSource->getType(),
					'id' => $esSource->getId(),
					'version' => $esSource->getVersion(),
					'source' => $esSource->getData(),
				];
			}
		}
		return $result;
	}

	/**
	 * Trace redirects to find the page id the title should be indexed to in
	 * cirrussearch. Differs from Updater::traceRedirects in that this also
	 * supports archived pages. Archive support is important for integration
	 * tests that need to know when a page that was deleted from SQL was
	 * finally removed from elasticsearch.
	 *
	 * This still fails to find the correct page id if something was moved, as
	 * that page is renamed rather than being moved to the archive. We could
	 * further complicate things by looking into move logs but not sure that
	 * is worth the complication.
	 *
	 * @param PageIdentity $title
	 * @param bool $followRedirects When false, stop at the requested page and return its
	 *  own doc id even if it is a redirect (redirect scope), rather than tracing through to
	 *  the redirect target.
	 * @return array Two element array containing first the cirrus doc id
	 *  the title should have been indexed into elasticsearch and second a
	 *  boolean indicating if redirects were followed. If the page would
	 *  not be indexed (for example a redirect loop, or redirect to
	 *  invalid page) the first array element will be null.
	 */
	private function determineCirrusDocId( PageIdentity $title, bool $followRedirects = true ) {
		$now = wfTimestamp( TS_MW );
		$services = MediaWikiServices::getInstance();
		$contentHandlerFactory = $services->getContentHandlerFactory();
		$archivedRevisionLookup = $services->getArchivedRevisionLookup();
		if ( !$followRedirects ) {
			$pageId = $title->getId();
			if ( $pageId === 0 ) {
				// The page is missing from SQL (e.g. recently deleted). Mirror the
				// redirect-following path below and recover its id from the archive so
				// redirect-scope queries can still locate the page's indexed document.
				$revRecord = $archivedRevisionLookup
					->getPreviousRevisionRecord( $title, $now );
				if ( $revRecord === null ) {
					return [ null, false ];
				}
				$pageId = $revRecord->getPageId();
			}
			return [ $this->getSearchConfig()->makeId( $pageId ), false ];
		}

		$hasRedirects = false;
		$seen = [];
		while ( true ) {
			$keySeen = $title->getNamespace() . '|' . $title->getDBkey();
			if ( isset( $seen[$keySeen] ) || count( $seen ) > 10 ) {
				return [ null, $hasRedirects ];
			}
			$seen[$keySeen] = true;

			// To help the integration tests figure out when a deleted page has
			// been removed from the elasticsearch index we lookup the page in
			// the archive to get it's page id. getPreviousRevisionRecord will
			// check both the archive and live content to return the most recent.
			$revRecord = $archivedRevisionLookup->getPreviousRevisionRecord( $title, $now );
			if ( !$revRecord ) {
				return [ null, $hasRedirects ];
			}

			$pageId = $revRecord->getPageId();
			$mainSlot = $revRecord->getSlot( SlotRecord::MAIN, RevisionRecord::RAW );
			$handler = $contentHandlerFactory->getContentHandler( $mainSlot->getModel() );
			if ( !$handler->supportsRedirects() ) {
				return [ $this->getSearchConfig()->makeId( $pageId ), $hasRedirects ];
			}
			$content = $mainSlot->getContent();
			// getUltimateRedirectTarget() would be prefered, but it wont find
			// archive pages...
			if ( !$content->isRedirect() ) {
				return [ $this->getSearchConfig()->makeId( $pageId ), $hasRedirects ];
			}
			$redirect = $content->getRedirectTarget();
			if ( !$redirect ) {
				// TODO: Can this happen?
				return [ $this->getSearchConfig()->makeId( $pageId ), $hasRedirects ];
			}

			$hasRedirects = true;
			$title = $redirect;
		}
	}

	/**
	 * @param array $source _source document from elasticsearch
	 * @param LinkTarget $title Title to check for redirect
	 * @return bool True when $title is stored as a redirect in $source
	 */
	private function hasRedirect( array $source, LinkTarget $title ) {
		if ( !isset( $source['redirect'] ) ) {
			return false;
		}
		foreach ( $source['redirect'] as $redirect ) {
			if ( $redirect['namespace'] === $title->getNamespace()
				&& $redirect['title'] === $title->getText()
			) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return User
	 */
	abstract public function getUser();

}
