<?php

namespace CirrusSearch\Query;

use CirrusSearch\Elastica\NeuralQuery;
use CirrusSearch\Search\SearchContext;
use CirrusSearch\SearchConfig;
use Elastica\Query\AbstractQuery;
use Elastica\Query\InnerHits;
use Elastica\Query\Nested;

/**
 * Query builder for semantic search using OpenSearch neural queries.
 *
 * This is a minimal implementation that skips most full-text search
 * functionality and generates a simple neural query for vector similarity search.
 * OpenSearch handles the embedding generation using the configured model.
 *
 * TODO: highlighting
 */
class SemanticSearchQueryBuilder implements FullTextQueryBuilder {
	public const SYNTAX_NAME = 'semantic';

	private string $nestedField;
	private string $vectorField;
	/** @var string[] */
	private array $sourceFields;
	private int $maxK;
	private string $scoreMode;
	private string $instructions;

	/**
	 * @param SearchConfig $config Not used, but part of standard constructor
	 * @param KeywordFeature[] $features Not used, but part of standard constructor
	 * @param array $settings Configuration settings for the builder
	 *   - nested_field: The name of the nested field holding paragraph embeddings (default: 'passage_chunk_embedding')
	 *   - vector_field: The name of the vector sub-field to search (default: 'knn')
	 *   - source_fields: The name of the sub-fields to return (default: section, text)
	 *   - maxK: Maximum number of requested results
	 *   - score_mode: ??? (default: max)
	 *   - instructions: If set, will be prepended to the query. (default: empty string)
	 */
	public function __construct( SearchConfig $config, array $features, array $settings = [] ) {
		$this->nestedField = $settings['nested_field'] ?? 'passage_chunk_embedding';
		$this->vectorField = $settings['vector_field'] ?? 'knn';
		$this->sourceFields = $settings['source_fields'] ?? [ 'section', 'text' ];
		$this->maxK = $settings['k'] ?? 21;
		$this->scoreMode = $settings['score_mode'] ?? 'max';
		$this->instructions = $settings['instructions'] ?? '';
	}

	/**
	 * Build a neural query for the supplied term.
	 *
	 * @param SearchContext $searchContext
	 * @param string $term term to search
	 */
	public function build( SearchContext $searchContext, $term ) {
		$searchContext->addSyntaxUsed( self::SYNTAX_NAME );

		$term = trim( $term );
		if ( $term === '' ) {
			$searchContext->addWarning( 'cirrussearch-semantic-empty-query' );
			$searchContext->setResultsPossible( false );
			return;
		}

		$query = $searchContext->getSearchQuery();
		$k = $query->getMaximumResultPosition();
		if ( $k > $this->maxK ) {
			$searchContext->addWarning( 'cirrussearch-semantic-too-many-results', $this->maxK );
			$k = $this->maxK;
		}

		// Clean search term is (currently) only used by the RescoreBuilder,
		// which we don't invoke for semantic search, but maybe someday we
		// will.
		$searchContext->setCleanedSearchTerm( $term );
		// We don't have the suggest field on semantic indices
		$searchContext->disableFallbackRunner();

		$searchContext->setMainQuery( $this->buildQuery( $term, $k ) );
	}

	private function buildQuery( string $term, int $k ): AbstractQuery {
		$source = [];
		foreach ( $this->sourceFields as $field ) {
			$source[] = "{$this->nestedField}.{$field}";
		}
		return ( new Nested() )
			->setPath( $this->nestedField )
			->setQuery( new NeuralQuery(
				"{$this->nestedField}.{$this->vectorField}",
				$this->instructions . $term,
				$k
			) )
			->setScoreMode( $this->scoreMode )
			->setInnerHits( ( new InnerHits() )
				->setSize( 1 )
				->setSource( $source )
			);
	}

	/**
	 * Semantic search does not support degraded queries.
	 *
	 * @param SearchContext $searchContext
	 * @return bool Always returns false
	 */
	public function buildDegraded( SearchContext $searchContext ) {
		return false;
	}
}
