<?php

namespace CirrusSearch\Query;

use CirrusSearch\CirrusSearchHookRunner;
use CirrusSearch\CirrusTestCase;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Search\SearchContext;
use CirrusSearch\Search\SearchQuery;
use Elastica\Query\Nested;
use Wikimedia\TestingAccessWrapper;

/**
 * @group CirrusSearch
 * @covers \CirrusSearch\Query\SemanticSearchQueryBuilder
 * @covers \CirrusSearch\Elastica\NeuralQuery
 */
class SemanticSearchQueryBuilderTest extends CirrusTestCase {
	public const MAX_RESULT_POS = 21;

	private function newSearchContext(): SearchContext {
		$context = new SearchContext(
			new HashSearchConfig( [] ), null, null, null, null,
			$this->createNoOpMock( CirrusSearchHookRunner::class )
		);
		TestingAccessWrapper::newFromObject( $context )
			->searchQuery = $this->newSearchQuery();
		return $context;
	}

	private function newSearchQuery(): SearchQuery {
		$mock = $this->createMock( SearchQuery::class );
		$mock->method( 'getMaximumResultPosition' )
			->willReturn( self::MAX_RESULT_POS );
		return $mock;
	}

	private function newSemanticSearchQueryBuilder( array $settings ) {
		$builder = new SemanticSearchQueryBuilder(
			new HashSearchConfig( [] ),
			[],
			$settings
		);
		return $builder;
	}

	public function testBuildWithEmptyQuery() {
		$builder = $this->newSemanticSearchQueryBuilder( [] );
		$context = $this->newSearchContext();

		$builder->build( $context, '' );

		$this->assertFalse( $context->areResultsPossible() );
		$this->assertContains(
			SemanticSearchQueryBuilder::SYNTAX_NAME,
			$context->getSyntaxUsed()
		);
		$warnings = $context->getWarnings();
		$this->assertCount( 1, $warnings );
		$this->assertSame( 'cirrussearch-semantic-empty-query', $warnings[0][0] );
	}

	public function testBuildWithWhitespaceOnlyQuery() {
		$builder = $this->newSemanticSearchQueryBuilder( [] );
		$context = $this->newSearchContext();

		$builder->build( $context, '   ' );

		$this->assertFalse( $context->areResultsPossible() );
		$warnings = $context->getWarnings();
		$this->assertCount( 1, $warnings );
		$this->assertSame( 'cirrussearch-semantic-empty-query', $warnings[0][0] );
	}

	public function testBuildSuccess() {
		$builder = $this->newSemanticSearchQueryBuilder( [
			'nested_field' => 'my_nested_field',
			'vector_field' => 'my_vector_field',
			'source_fields' => [ 'a', 'b' ],
			'k' => 21,
			'score_mode' => 'min',
		] );
		$context = $this->newSearchContext();

		$builder->build( $context, 'search term' );

		$this->assertTrue( $context->areResultsPossible() );
		$this->assertCount( 0, $context->getWarnings() );
		$this->assertContains(
			SemanticSearchQueryBuilder::SYNTAX_NAME,
			$context->getSyntaxUsed()
		);
		$this->assertSame( 'search term', $context->getCleanedSearchTerm() );

		// Verify the query structure
		$query = $context->getQuery();
		$this->assertInstanceOf( Nested::class, $query );
		$queryArray = $query->toArray();

		$this->assertSame( 'my_nested_field', $queryArray['nested']['path'] );
		$this->assertSame( 'min', $queryArray['nested']['score_mode'] );
		$this->assertSame(
			[
				'my_nested_field.a',
				'my_nested_field.b',
			],
			$queryArray['nested']['inner_hits']['_source']
		);

		$this->assertArrayHasKey( 'neural', $queryArray['nested']['query'] );
		$neuralArray = $queryArray['nested']['query'];
		$key = 'my_nested_field.my_vector_field';
		$this->assertArrayHasKey( $key, $neuralArray['neural'] );
		$this->assertSame( 'search term', $neuralArray['neural'][$key]['query_text'] );
		$this->assertSame( 21, $neuralArray['neural'][$key]['k'] );
	}

	public function testBuildWithDefaultSettings() {
		$builder = $this->newSemanticSearchQueryBuilder( [] );
		$context = $this->newSearchContext();

		$builder->build( $context, 'test' );

		$this->assertTrue( $context->areResultsPossible() );

		$query = $context->getQuery();
		$queryArray = $query->toArray();
		$this->assertArrayHasKey( 'passage_chunk_embedding.knn', $queryArray['nested']['query']['neural'] );
		$this->assertSame( 21, $queryArray['nested']['query']['neural']['passage_chunk_embedding.knn']['k'] );
	}

	public function testBuildTrimsQueryTerm() {
		$builder = $this->newSemanticSearchQueryBuilder( [] );
		$context = $this->newSearchContext();

		$builder->build( $context, '  search with spaces  ' );

		$this->assertTrue( $context->areResultsPossible() );
		$this->assertSame( 'search with spaces', $context->getCleanedSearchTerm() );

		$query = $context->getQuery();
		$queryArray = $query->toArray();
		$this->assertSame( 'search with spaces', $queryArray['nested']['query']['neural']['passage_chunk_embedding.knn']['query_text'] );
	}

	public function testBuildPrependsInstructions() {
		$builder = $this->newSemanticSearchQueryBuilder( [
			'instructions' => 'Represent this query: ',
		] );
		$context = $this->newSearchContext();

		$builder->build( $context, 'search term' );

		$this->assertTrue( $context->areResultsPossible() );
		$this->assertSame( 'search term', $context->getCleanedSearchTerm() );

		$queryArray = $context->getQuery()->toArray();
		$this->assertSame(
			'Represent this query: search term',
			$queryArray['nested']['query']['neural']['passage_chunk_embedding.knn']['query_text']
		);
	}

	public function testBuildWithEmptyInstructionsDoesNotAlterQuery() {
		$builder = $this->newSemanticSearchQueryBuilder( [
			'instructions' => '',
		] );
		$context = $this->newSearchContext();

		$builder->build( $context, 'search term' );

		$queryArray = $context->getQuery()->toArray();
		$this->assertSame(
			'search term',
			$queryArray['nested']['query']['neural']['passage_chunk_embedding.knn']['query_text']
		);
	}

	public function testBuildDegradedReturnsFalse() {
		$builder = $this->newSemanticSearchQueryBuilder( [] );
		$context = $this->newSearchContext();

		$this->assertFalse( $builder->buildDegraded( $context ) );
	}
}
