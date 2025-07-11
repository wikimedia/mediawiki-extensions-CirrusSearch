<?php

namespace CirrusSearch\Api;

use CirrusSearch\BuildDocument\Completion\SuggestBuilder;
use InvalidArgumentException;
use MediaWiki\Api\ApiQuery;
use MediaWiki\Api\ApiQueryBase;
use Wikimedia\ParamValidator\ParamValidator;

class QueryCompSuggestBuildDoc extends ApiQueryBase {
	use ApiTrait;

	public function __construct( ApiQuery $query, string $moduleName ) {
		parent::__construct( $query, $moduleName, 'csb' );
	}

	/**
	 * @inheritDoc
	 */
	public function execute() {
		$method = $this->getParameter( 'method' );
		try {
			$builder = SuggestBuilder::create( $this->getCirrusConnection(), $method );
		} catch ( InvalidArgumentException ) {
			$this->addError( 'apierror-compsuggestbuilddoc-bad-method' );
			return;
		}

		foreach ( $this->getPageSet()->getGoodPages() as $origPageId => $title ) {
			$docs = $this->loadDocuments( $title );
			$this->addExplanation( $builder, $origPageId, $docs );
		}
	}

	/** @inheritDoc */
	protected function getAllowedParams() {
		return [
			'method' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => $this->getSearchConfig()->get( 'CirrusSearchCompletionDefaultScore' ),
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

	private function addExplanation( SuggestBuilder $builder, int $pageId, array $docs ) {
		$result = $this->getResult();
		foreach ( $builder->build( $docs, true ) as $d ) {
			$result->addValue(
				[ 'query', 'pages', $pageId ],
				'cirruscompsuggestbuilddoc',
				[ $d->getId() => $d->getData() ]
			);
		}
	}
}
