<?php

namespace CirrusSearch\Api;

use MediaWiki\Api\ApiQuery;
use MediaWiki\Api\ApiQueryBase;
use MediaWiki\Page\PageIdentity;
use Wikimedia\ParamValidator\ParamValidator;

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
class QueryCirrusDoc extends ApiQueryBase {
	use ApiTrait;

	public function __construct( ApiQuery $query, $moduleName ) {
		parent::__construct( $query, $moduleName, 'cd' );
	}

	public function execute() {
		$sourceFiltering = $this->generateSourceFiltering();
		foreach ( $this->getPageSet()->getGoodPages() as $origPageId => $title ) {
			$this->addByPageId( $origPageId, $title, $sourceFiltering );
		}

		// Not 100% sure we need deletedhistory, but better safe than sorry
		if ( $this->getUser()->isAllowed( 'deletedhistory' ) ) {
			foreach ( $this->getPageSet()->getMissingPages() as $resultPageId => $title ) {
				$this->addByPageId( $resultPageId, $title, $sourceFiltering );
			}
		}
	}

	/**
	 * @param int $resultPageId The page id as represented in the api result.
	 *  This may be negative for missing pages. If those pages were recently
	 *  deleted they could still be in the elastic index.
	 * @param PageIdentity $title The requested title
	 * @param string[]|bool $sourceFiltering source filtering to apply
	 */
	private function addByPageId( $resultPageId, PageIdentity $title, $sourceFiltering ) {
		$this->getResult()->addValue(
			[ 'query', 'pages', $resultPageId ],
			'cirrusdoc', $this->loadDocuments( $title, $sourceFiltering )
		);
	}

	/**
	 * @return array|bool
	 */
	private function generateSourceFiltering() {
		$params = $this->extractRequestParams();
		$sourceFiltering = (array)$params['includes'];
		$includeAll = in_array( 'all', $sourceFiltering );

		if ( !$sourceFiltering || $includeAll ) {
			return true;
		} else {
			return $sourceFiltering;
		}
	}

	public function getAllowedParams() {
		return [
			'includes' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => 'all',
				ParamValidator::PARAM_ISMULTI => true,
			],
		];
	}

	/**
	 * @see ApiBase::getExamplesMessages
	 * @return array
	 */
	protected function getExamplesMessages() {
		return [
			'action=query&prop=cirrusdoc&titles=Main_Page' =>
				'apihelp-query+cirrusdoc-example',
			'action=query&prop=cirrusdoc&titles=Main_Page&cdincludes=category' =>
				'apihelp-query+cirrusdoc-example-2'
		];
	}

}
