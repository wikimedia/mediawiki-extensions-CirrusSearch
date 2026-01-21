<?php

namespace CirrusSearch\Search;

use Elastica\ResultSet as ElasticaResultSet;

/**
 * Result type for a full text search.
 */
final class SemanticResultsType extends BaseResultsType {
	private TitleHelper $titleHelper;
	/* @var string[] list of extra fields to extract */
	private array $extraFieldsToExtract;
	private array $queryBuilderProfile;

	/**
	 * @param TitleHelper $titleHelper
	 * @param string[] $extraFieldsToExtract
	 */
	public function __construct(
		TitleHelper $titleHelper,
		array $extraFieldsToExtract,
		array $queryBuilderProfile,
	) {
		$this->titleHelper = $titleHelper;
		$this->extraFieldsToExtract = $extraFieldsToExtract;
		$this->queryBuilderProfile = $queryBuilderProfile;
	}

	/**
	 * @return false|string|array corresponding to Elasticsearch source filtering syntax
	 */
	public function getSourceFiltering() {
		return array_merge(
			parent::getSourceFiltering(),
			[ 'timestamp', 'text_bytes' ],
			$this->extraFieldsToExtract
		);
	}

	/**
	 * @return array
	 */
	public function getFields() {
		return [ "text.word_count" ]; // word_count is only a stored field and isn't part of the source.
	}

	/**
	 * @param array $extraHighlightFields (deprecated and ignored)
	 * @return array|null of highlighting configuration
	 */
	public function getHighlightingConfiguration( array $extraHighlightFields = [] ) {
		return null;
	}

	/**
	 * @param ElasticaResultSet $result
	 * @return CirrusSearchResultSet
	 */
	public function transformElasticsearchResult( ElasticaResultSet $result ) {
		// Should we make this a concrete class?
		return new class(
			$this->titleHelper,
			$result,
			$this->extraFieldsToExtract,
			$this->queryBuilderProfile,
		) extends BaseCirrusSearchResultSet {
			private TitleHelper $titleHelper;
			private SemanticSearchResultBuilder $resultBuilder;
			private ElasticaResultSet $results;

			/**
			 * @param TitleHelper $titleHelper
			 * @param ElasticaResultSet $results
			 * @param string[] $extraFieldsToExtract
			 */
			public function __construct(
				TitleHelper $titleHelper,
				ElasticaResultSet $results,
				array $extraFieldsToExtract,
				array $queryBuilderProfile,
			) {
				$this->titleHelper = $titleHelper;
				$this->resultBuilder = new SemanticSearchResultBuilder(
					$this->titleHelper,
					// This is a bit awkward, we didn't have a nice way to pass between the qb
					// and the results type. For now it's this hack.
					$queryBuilderProfile['settings']['nested_field'],
					$queryBuilderProfile['settings']['snippet_field'],
					$queryBuilderProfile['settings']['anchor_field'],
					$extraFieldsToExtract
				);
				$this->results = $results;
			}

			/**
			 * @inheritDoc
			 */
			protected function transformOneResult( \Elastica\Result $result ) {
				return $this->resultBuilder->build( $result );
			}

			/**
			 * @return \Elastica\ResultSet|null
			 */
			public function getElasticaResultSet() {
				return $this->results;
			}

			/**
			 * @inheritDoc
			 */
			public function searchContainedSyntax() {
				return false;
			}

			protected function getTitleHelper(): TitleHelper {
				return $this->titleHelper;
			}
		};
	}

	/**
	 * @return CirrusSearchResultSet
	 */
	public function createEmptyResult() {
		return BaseCirrusSearchResultSet::emptyResultSet();
	}
}
