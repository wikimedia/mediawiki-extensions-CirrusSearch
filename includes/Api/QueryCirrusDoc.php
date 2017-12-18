<?php

namespace CirrusSearch\Api;

use CirrusSearch\Searcher;
use CirrusSearch\SearchConfig;
use PageArchive;
use Title;

/**
 * Dump stored CirrusSearch document for page.
 *
 * This was primarily written for the integration tests, but may be useful
 * elsewhere. This is functionally similar to web action=cirrusdump but
 * available and discoverable over the API. Compared to cirrusdump this
 * also takes pain to try and ensure if there is a related elastic document,
 * even if its not in-sync with the sql database, we return it. Similarly
 * if a document in elasticsearch should, but does not, match the requested
 * page (perhaps a redirect has been created but not indexed yet) it will
 * not be returned. In this way this tries to faithfully return the document
 * in elasticsearch that represents the requested page.
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
class QueryCirrusDoc extends \ApiQueryBase {
	use ApiTrait;

	/** @var SearchConfig */
	private $config;
	/** @var Searcher */
	private $searcher;

	public function __construct( \ApiQuery $query, $moduleName ) {
		parent::__construct( $query, $moduleName, 'cd' );
	}

	public function execute() {
		$conn = $this->getCirrusConnection();
		$this->config = $this->getSearchConfig();
		$this->searcher = new Searcher( $conn, 0, 0, $this->config, [], $this->getUser() );
		foreach ( $this->getPageSet()->getGoodTitles() as $origPageId => $title ) {
			$this->addByPageId( $origPageId, $title );
		}

		// Not 100% sure we need deletedhistory, but better safe than sorry
		if ( $this->getUser()->isAllowed( 'deletedhistory' ) ) {
			foreach ( $this->getPageSet()->getMissingTitles() as $resultPageId => $title ) {
				$this->addByPageId( $resultPageId, $title );
			}
		}
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
	 * @param Title $title
	 * @return array Two element array containing first the cirrus doc id
	 *  the title should have been indexed into elasticsearch and second a
	 *  boolean indicating if redirects were followed. If the page would
	 *  not be indexed (for example a redirect loop, or redirect to
	 *  invalid page) the first array element will be null.
	 */
	private function determineCirrusDocId( Title $title ) {
		$hasRedirects = false;
		$seen = [];
		$now = wfTimestamp( TS_MW );
		while ( true ) {
			if ( isset( $seen[$title->getPrefixedText()] ) || count( $seen ) > 10 ) {
				return [ null, $hasRedirects ];
			}
			$seen[$title->getPrefixedText()] = true;

			// To help the integration tests figure out when a deleted page has
			// been removed from the elasticsearch index we lookup the page in
			// the archive to get it's page id. getPreviousRevision will check
			// both the archive and live content to return the most recent.
			$rev = ( new PageArchive( $title, $this->getConfig() ) )
				->getPreviousRevision( $now );
			if ( !$rev ) {
				return [ null, $hasRedirects ];
			}
			$handler = $rev->getContentHandler();
			if ( !$handler->supportsRedirects() ) {
				return [ $rev->getPage(), $hasRedirects ];
			}
			$content = $handler->unserializeContent(
			/** @suppress PhanDeprecatedFunction TODO move to new API  */
				$rev->getSerializedData(),
				$rev->getContentFormat()
			);
			// getUltimateRedirectTarget() would be prefered, but it wont find
			// archive pages...
			if ( !$content->isRedirect() ) {
				return [ $this->config->makeId( $rev->getPage() ), $hasRedirects ];
			}
			$redirect = $content->getRedirectTarget();
			if ( !$redirect ) {
				// TODO: Can this happen?
				return [ $rev->getPage(), $hasRedirects ];
			}

			$hasRedirects = true;
			$title = $redirect;
		}
	}

	/**
	 * @param int $resultPageId The page id as represented in the api result.
	 *  This may be negative for missing pages. If those pages were recently
	 *  deleted they could still be in the elastic index.
	 * @param Title|null The requested title
	 */
	private function addByPageId( $resultPageId, Title $title ) {
		list( $docId, $hasRedirects ) = $this->determineCirrusDocId( $title );
		if ( $docId === null ) {
			return;
		}
		// could be optimized by implementing multi-get but not
		// expecting much usage except debugging/tests.
		$esSources = $this->searcher->get( [ $docId ], true );
		$result = [];
		if ( $esSources->isOK() ) {
			foreach ( $esSources->getValue() as $i => $esSource ) {
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
		$this->getResult()->addValue(
			[ 'query', 'pages', $resultPageId ],
			'cirrusdoc', $result
		);
	}

	/**
	 * @param array $source _source document from elasticsearch
	 * @param Title $title Title to check for redirect
	 * @return bool True when $title is stored as a redirect in $source
	 */
	private function hasRedirect( array $source, Title $title ) {
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

	public function getAllowedParams() {
		return [];
	}

	/**
	 * @deprecated since MediaWiki core 1.25
	 */
	public function getDescription() {
		return 'Dump stored CirrusSearch document for page.';
	}

	/**
	 * @see ApiBase::getExamplesMessages
	 * @return array
	 */
	protected function getExamplesMessages() {
		return [
			'action=query&prop=cirrusdoc&titles=Main_Page' =>
				'apihelp-query+cirrusdoc-example'
		];
	}

}
