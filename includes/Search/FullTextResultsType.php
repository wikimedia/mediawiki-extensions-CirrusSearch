<?php

namespace CirrusSearch\Search;

use CirrusSearch\Search\Fetch\FetchPhaseConfigBuilder;
use Elastica\ResultSet as ElasticaResultSet;

/**
 * Result type for a full text search.
 */
final class FullTextResultsType extends BaseResultsType {
	/**
	 * @var bool
	 */
	private $searchContainedSyntax;

	/**
	 * @var FetchPhaseConfigBuilder
	 */
	private $fetchPhaseBuilder;
	/**
	 * @var TitleHelper
	 */
	private $titleHelper;

	/**
	 * @var string[] list of extra fields to extract
	 */
	private $extraFieldsToExtract;

	/**
	 * @var bool if true, deduplicate results from file namespace
	 */
	private bool $deduplicate;

	/**
	 * @param FetchPhaseConfigBuilder $fetchPhaseBuilder
	 * @param bool $searchContainedSyntax
	 * @param TitleHelper $titleHelper
	 * @param string[] $extraFieldsToExtract
	 * @param bool $deduplicate
	 */
	public function __construct(
		FetchPhaseConfigBuilder $fetchPhaseBuilder,
		$searchContainedSyntax,
		TitleHelper $titleHelper,
		array $extraFieldsToExtract = [],
		bool $deduplicate = false
	) {
		$this->fetchPhaseBuilder = $fetchPhaseBuilder;
		$this->searchContainedSyntax = $searchContainedSyntax;
		$this->titleHelper = $titleHelper;
		$this->extraFieldsToExtract = $extraFieldsToExtract;
		$this->deduplicate = $deduplicate;
	}

	/**
	 * @return false|string|array corresponding to Elasticsearch source filtering syntax
	 */
	public function getSourceFiltering() {
		return array_merge(
			parent::getSourceFiltering(),
			[ 'redirect.*', 'timestamp', 'text_bytes' ],
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
	 * Setup highlighting.
	 * Don't fragment title because it is small.
	 * Get just one fragment from the text because that is all we will display.
	 * Get one fragment from redirect title and heading each or else they
	 * won't be sorted by score.
	 *
	 * @param array $extraHighlightFields (deprecated and ignored)
	 * @return array|null of highlighting configuration
	 */
	public function getHighlightingConfiguration( array $extraHighlightFields = [] ) {
		$this->fetchPhaseBuilder->configureDefaultFullTextFields();
		return $this->fetchPhaseBuilder->buildHLConfig();
	}

	/**
	 * @param ElasticaResultSet $result
	 * @return CirrusSearchResultSet
	 */
	public function transformElasticsearchResult( ElasticaResultSet $result ) {
		// Should we make this a concrete class?
		return new class(
			$this->titleHelper,
			$this->fetchPhaseBuilder,
			$result,
			$this->searchContainedSyntax,
			$this->extraFieldsToExtract,
			$this->deduplicate
		) extends BaseCirrusSearchResultSet {
			/** @var TitleHelper */
			private $titleHelper;
			/** @var FullTextCirrusSearchResultBuilder */
			private $resultBuilder;
			/** @var ElasticaResultSet */
			private $results;
			/** @var bool */
			private $searchContainedSyntax;
			/** @var bool if true, deduplicate results from file namespace */
			private bool $deduplicate;
			/** @var string[] array of titles for counting duplicates */
			private array $fileTitles = [];

			public function __construct(
				TitleHelper $titleHelper,
				FetchPhaseConfigBuilder $builder,
				ElasticaResultSet $results,
				$searchContainedSyntax,
				array $extraFieldsToExtract,
				bool $deduplicate
			) {
				$this->titleHelper = $titleHelper;
				$this->resultBuilder = new FullTextCirrusSearchResultBuilder( $this->titleHelper,
					$builder->getHLFieldsPerTargetAndPriority(), $extraFieldsToExtract );
				$this->results = $results;
				$this->searchContainedSyntax = $searchContainedSyntax;
				$this->deduplicate = $deduplicate;
			}

			/**
			 * @inheritDoc
			 */
			protected function transformOneResult( \Elastica\Result $result ) {
				$source = $result->getSource();
				if ( $source['namespace'] === NS_FILE ) {
					if ( in_array( $source['title'], $this->fileTitles ) ) {
						if ( $this->deduplicate ) {
							return null;
						}
					} else {
						$this->fileTitles[] = $source['title'];
					}
				}
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
				return $this->searchContainedSyntax;
			}

			protected function getTitleHelper(): TitleHelper {
				return $this->titleHelper;
			}
		};
	}

	/**
	 * @param FetchPhaseConfigBuilder $builder
	 * @return FullTextResultsType
	 */
	public function withFetchPhaseBuilder( FetchPhaseConfigBuilder $builder ): FullTextResultsType {
		return new self( $builder, $this->searchContainedSyntax, $this->titleHelper, $this->extraFieldsToExtract, $this->deduplicate );
	}

	/**
	 * @return CirrusSearchResultSet
	 */
	public function createEmptyResult() {
		return BaseCirrusSearchResultSet::emptyResultSet();
	}
}
