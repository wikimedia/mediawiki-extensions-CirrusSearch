<?php

namespace CirrusSearch\Search;

use CirrusSearch\CirrusIntegrationTestCase;
use Elastica\Query;
use Elastica\Response;
use Elastica\ResultSet;
use MediaWiki\Page\LinkBatchFactory;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;

/**
 * @covers \CirrusSearch\Search\SemanticResultsType
 * @group CirrusSearch
 * @todo Make this a unit test when moving away from Title(Factory)
 */
class SemanticResultsTypeTest extends CirrusIntegrationTestCase {
	private const NESTED_FIELD = 'passage_chunk_embedding';
	private const SNIPPET_FIELD = 'text';
	private const ANCHOR_FIELD = 'section';

	protected function setUp(): void {
		parent::setUp();
		$this->setService( 'LinkBatchFactory', $this->createMock( LinkBatchFactory::class ) );
		$titleFactory = $this->createMock( TitleFactory::class );
		$titleFactory->method( 'makeTitle' )->willReturnCallback( static function ( $ns, $title, $fragment = '', $interwiki = '' ) {
				$ret = Title::makeTitle( $ns, $title, $fragment, $interwiki );
				$ret->resetArticleID( 0 );
				return $ret;
		} );
		$this->setService( 'TitleFactory', $titleFactory );
	}

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

	public function testHighlights(): void {
		$type = $this->newSemanticResultsType();
		$responseData = self::loadFixture( "semanticResultsType/highlight_response.json" );
		$res = new Response( $responseData );

		$builder = new ResultSet\DefaultBuilder();
		$elasticaResultSet = $builder->buildResultSet( new Response( $responseData, 200 ), new Query( [] ) );
		$resultSet = $type->transformElasticsearchResult( $elasticaResultSet );
		$this->assertEquals( 3, $resultSet->getTotalHits() );
		$results = $resultSet->extractResults();
		$this->assertCount( 3, $results );
		$this->assertEquals(
			'Jupiter has <span class="searchmatch">115</span> moons with known orbits announced; 73 of them have ' .
			'received permanent designations, and 57 have been named. Its eight regular moons are grouped into the planet-sized ' .
			'Galilean moons and the far smaller Amalthea group. They were named after lovers of Zeus, the Greek equivalent of Jupiter. ' .
			'Among them is Ganymede, the largest and most massive moon in the Solar System. The rest are irregular moons, which are ' .
			'organized into two categories: prograde and retrograde. The prograde satellites consist of the Himalia group ' .
			'and three others in groups of one. The retrograde moons are grouped into the Carme, Ananke and Pasiphae groups.',
			$results[1]->getTextSnippet()
		);
		$this->assertEquals( 'Moons by primary', $results[1]->getSectionTitle()->getFragment() );
	}
}
