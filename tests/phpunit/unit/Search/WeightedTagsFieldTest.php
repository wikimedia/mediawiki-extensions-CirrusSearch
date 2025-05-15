<?php

namespace CirrusSearch\Search;

/**
 * @covers \CirrusSearch\Search\WeightedTags
 */
class WeightedTagsFieldTest extends \MediaWikiUnitTestCase {
	public function testField() {
		$searchEngine = $this->createNoOpMock( \SearchEngine::class );
		$indexAnalyzer = 'indexAnalyzer';
		$searchAnalyzer = 'searchAnalyzer';
		$similarity = 'sim';
		$fieldName = 'test';
		$typeName = 'unused';
		$field = new WeightedTags( 'test', 'unused', $indexAnalyzer,
			$searchAnalyzer, $similarity );
		$mapping = $field->getMapping( $searchEngine );
		$this->assertSame( [
			'type' => 'text',
			'analyzer' => $indexAnalyzer,
			'search_analyzer' => $searchAnalyzer,
			'index_options' => 'freqs',
			'norms' => false,
			'similarity' => $similarity,
		], $mapping );
		$this->assertSame( $fieldName, $field->getName() );
		$this->assertSame( $typeName, $field->getIndexType() );
	}
}
