<?php

namespace CirrusSearch\Api;

use CirrusSearch\Searcher;
use CirrusSearch\Updater;
use Title;

/**
 * Dump stored CirrusSearch document for page.
 *
 * This was primarily written for the integration tests, but may be useful
 * elsewhere. This is functionally similar to web action=cirrusdump but
 * available and discoverable over the API.
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

	public function __construct( \ApiQuery $query, $moduleName ) {
		parent::__construct( $query, $moduleName, 'cd' );
	}

	public function execute() {
		$conn = $this->getCirrusConnection();
		$config = $this->getSearchConfig();
		$updater = new Updater( $conn, $config );
		$searcher = new Searcher( $conn, 0, 0, $config, [], $this->getUser() );
		$result = [];
		foreach ( $this->getPageSet()->getGoodTitles() as $origPageId => $title ) {
			list( $page, $redirects ) = $updater->traceRedirects( $title );

			$result = [];
			if ( $page ) {
				$docId = $config->makeId( $page->getId() );
				// could be optimized by implementing multi-get but not
				// expecting much usage except debugging/tests.
				$esSources = $searcher->get( [ $docId ], true );
				if ( $esSources->isOK() ) {
					foreach ( $esSources->getValue() as $i => $esSource ) {
						// If we have followed redirects only report the
						// article dump if the redirect has been indexed. If it
						// hasn't been indexed this document does not represent
						// the original title.
						if ( count( $redirects ) &&
							!$this->hasRedirect( $esSource->getData(), $title )
						) {
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
			}
			$this->getResult()->addValue(
				[ 'query', 'pages', $origPageId ],
				'cirrusdoc', $result
			);
		}
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
