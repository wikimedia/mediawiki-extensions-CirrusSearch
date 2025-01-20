<?php

namespace CirrusSearch\Api;

use CirrusSearch\BuildDocument\Completion\SuggestBuilder;
use Elastica\Document;
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
		} catch ( InvalidArgumentException $e ) {
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

	private function addExplanation( SuggestBuilder $builder, int $pageId, array $docs ) {
		$docs = array_map(
			static function ( Document $d ) {
				return [ $d->getId() => $d->getData() ];
			}, $builder->build( $docs, true )
		);

		foreach ( $docs as $doc ) {
			$this->getResult()->addValue(
				[ 'query', 'pages', $pageId ],
				'cirruscompsuggestbuilddoc',
				$doc
			);
		}
	}
}
