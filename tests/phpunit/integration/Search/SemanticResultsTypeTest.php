<?php

namespace CirrusSearch\Search;

use CirrusSearch\CirrusIntegrationTestCase;
use Elastica\Query;
use Elastica\Response;
use Elastica\ResultSet;

/**
 * @covers \CirrusSearch\Search\SemanticResultsType
 * @group CirrusSearch
 * @todo Make this a unit test when moving away from Title(Factory)
 */
class SemanticResultsTypeTest extends CirrusIntegrationTestCase {
	private const NESTED_FIELD = 'passage_chunk_embedding';
	private const SNIPPET_FIELD = 'text';
	private const ANCHOR_FIELD = 'section';

	private function newSemanticResultsType( array $extraFields = [] ): SemanticResultsType {
		return new SemanticResultsType(
			self::newTitleHelper(),
			$extraFields,
			[
				'settings' => [
					'nested_field' => self::NESTED_FIELD,
					'snippet_field' => self::SNIPPET_FIELD,
					'anchor_field' => self::ANCHOR_FIELD,
				]
			]
		);
	}

	public function testGetSourceFilteringContainsBaseAndSemanticFields(): void {
		$type = $this->newSemanticResultsType();
		$fields = $type->getSourceFiltering();
		foreach ( [ 'namespace', 'title', 'namespace_text', 'wiki', 'timestamp', 'text_bytes' ] as $expected ) {
			$this->assertContains( $expected, $fields, "getSourceFiltering() must include '$expected'" );
		}
	}

	public function testGetSourceFilteringIncludesExtraFields(): void {
		$type = $this->newSemanticResultsType( [ 'extra_field1', 'extra_field2' ] );
		$fields = $type->getSourceFiltering();
		$this->assertContains( 'extra_field1', $fields );
		$this->assertContains( 'extra_field2', $fields );
	}

	public function testGetFields(): void {
		$type = $this->newSemanticResultsType();
		$this->assertSame( [ 'text.word_count' ], $type->getFields() );
	}

	public function testGetHighlightingConfigurationReturnsNull(): void {
		$type = $this->newSemanticResultsType();
		$this->assertNull( $type->getHighlightingConfiguration() );
	}

	public function testCreateEmptyResult(): void {
		$type = $this->newSemanticResultsType();
		$result = $type->createEmptyResult();
		$this->assertSame( 0, $result->numRows() );
		$this->assertFalse( $result->hasMoreResults() );
	}

	public function testSearchContainedSyntaxAlwaysFalse(): void {
		$type = $this->newSemanticResultsType();
		$res = new ResultSet( new Response( [] ), new Query( [] ), [] );
		$this->assertFalse( $type->transformElasticsearchResult( $res )->searchContainedSyntax() );
	}
}
