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
 * @license GPL-2.0-or-later
 */
class QueryCirrusDoc extends ApiQueryBase {
	use ApiTrait;

	public function __construct( ApiQuery $query, string $moduleName ) {
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
		$this->getResult()->addValue(
			[ 'query', 'pages', $resultPageId ],
			'cirrusdoc_comment',
			'The CirrusDoc format is meant for internal use by CirrusSearch for debugging or queries, '
			. 'it might change at any time without notice'
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

	/** @inheritDoc */
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
	 * Mark as internal. This isn't meant to be used by normal api users
	 * @return bool
	 */
	public function isInternal() {
		return true;
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
