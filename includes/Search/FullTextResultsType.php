<?php

namespace CirrusSearch\Search;

use CirrusSearch\Search\Fetch\FetchedFieldBuilder;
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
	 * @var FetchPhaseConfigBuilder $fetchPhaseBuilder
	 */
	private $fetchPhaseBuilder;
	/**
	 * @var TitleHelper|null
	 */
	private $titleHelper;

	/**
	 * @param FetchPhaseConfigBuilder $fetchPhaseBuilder
	 * @param bool $searchContainedSyntax
	 * @param TitleHelper|null $titleHelper
	 */
	public function __construct(
		FetchPhaseConfigBuilder $fetchPhaseBuilder,
		$searchContainedSyntax = false,
		TitleHelper $titleHelper = null
	) {
		$this->fetchPhaseBuilder = $fetchPhaseBuilder;
		$this->searchContainedSyntax = $searchContainedSyntax;
		$this->titleHelper = $titleHelper;
	}

	/**
	 * @return false|string|array corresponding to Elasticsearch source filtering syntax
	 */
	public function getSourceFiltering() {
		return array_merge(
			parent::getSourceFiltering(),
			[ 'redirect.*', 'timestamp', 'text_bytes' ]
		);
	}

	/**
	 * @return array
	 */
	public function getStoredFields() {
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
		// Title/redir/category/template
		$field = $this->fetchPhaseBuilder->newHighlightField( 'title', FetchedFieldBuilder::TARGET_TITLE_SNIPPET );
		$this->fetchPhaseBuilder->addHLField( $field );
		$field = $this->fetchPhaseBuilder->newHighlightField( 'redirect.title', FetchedFieldBuilder::TARGET_REDIRECT_SNIPPET );
		$this->fetchPhaseBuilder->addHLField( $field->skipIfLastMatched() );
		$field = $this->fetchPhaseBuilder->newHighlightField( 'category', FetchedFieldBuilder::TARGET_CATEGORY_SNIPPET );
		$this->fetchPhaseBuilder->addHLField( $field->skipIfLastMatched() );

		$field = $this->fetchPhaseBuilder->newHighlightField( 'heading', FetchedFieldBuilder::TARGET_CATEGORY_SNIPPET );
		$this->fetchPhaseBuilder->addHLField( $field->skipIfLastMatched() );

		// content
		$field = $this->fetchPhaseBuilder->newHighlightField( 'text', FetchedFieldBuilder::TARGET_MAIN_SNIPPET );
		$this->fetchPhaseBuilder->addHLField( $field );

		$field = $this->fetchPhaseBuilder->newHighlightField( 'auxiliary_text', FetchedFieldBuilder::TARGET_MAIN_SNIPPET );
		$this->fetchPhaseBuilder->addHLField( $field->skipIfLastMatched() );

		$field = $this->fetchPhaseBuilder->newHighlightField( 'file_text', FetchedFieldBuilder::TARGET_MAIN_SNIPPET );
		$this->fetchPhaseBuilder->addHLField( $field->skipIfLastMatched() );

		return $this->fetchPhaseBuilder->buildHLConfig();
	}

	/**
	 * @param ElasticaResultSet $result
	 * @return CirrusSearchResultSet
	 */
	public function transformElasticsearchResult( ElasticaResultSet $result ) {
		return new ResultSet(
			$this->searchContainedSyntax,
			$result,
			$this->titleHelper
		);
	}

	/**
	 * @param FetchPhaseConfigBuilder $builder
	 * @return FullTextResultsType
	 */
	public function withFetchPhaseBuilder( FetchPhaseConfigBuilder $builder ): FullTextResultsType {
		return new self( $builder, $this->searchContainedSyntax, $this->titleHelper );
	}

	/**
	 * @return CirrusSearchResultSet
	 */
	public function createEmptyResult() {
		return ResultSet::emptyResultSet();
	}
}
