<?php

namespace CirrusSearch\Search\Fetch;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Search\SearchQuery;
use CirrusSearch\Searcher;
use Elastica\Query\MatchAll;

/**
 * @covers \CirrusSearch\Search\Fetch\FetchPhaseConfigBuilder
 */
class FetchPhaseConfigBuilderTest extends CirrusTestCase {
	public function testNewHighlightField() {
		$configDef = new HashSearchConfig( [ 'CirrusSearchUseExperimentalHighlighter' => false ] );
		$configExp = new HashSearchConfig( [ 'CirrusSearchUseExperimentalHighlighter' => true ] );
		$builders = [ new FetchPhaseConfigBuilder( $configDef ), new FetchPhaseConfigBuilder( $configExp ) ];
		foreach ( $builders as $builder ) {
			/** @var BaseHighlightedFieldBuilder $field */
			$field = $builder->newHighlightField( 'myName', 'myTarget', 321 );
			$this->assertEquals( 'myName', $field->getFieldName() );
			$this->assertEquals( 'myTarget', $field->getTarget() );
			$this->assertEquals( 321, $field->getPriority() );
		}
	}

	public function provideNewHighlightFieldWithFactory() {
		$tests = [];
		$configBase = new HashSearchConfig( [
			'CirrusSearchFragmentSize' => 350,
			'CirrusSearchUseExperimentalHighlighter' => false
		] );
		$configExp = new HashSearchConfig( [
			'CirrusSearchFragmentSize' => 350,
			'CirrusSearchUseExperimentalHighlighter' => true
		] );
		$factoryGroups = [
			SearchQuery::SEARCH_TEXT => [
				'title',
				'redirect.title',
				'category',
				'heading',
				'text',
				'auxiliary_text',
				'file_text',
				'source_text.plain'
			]
		];

		foreach ( $factoryGroups as $factoryGroup => $fields ) {
			foreach ( $fields as $field ) {
				$tests["$factoryGroup-$field-base"] = [
					CirrusTestCase::FIXTURE_DIR . "/highlightFieldBuilder/$factoryGroup-$field-base.expected",
					$factoryGroup,
					$field,
					$configBase
				];
				$tests["$factoryGroup-$field-exp"] = [
					CirrusTestCase::FIXTURE_DIR . "/highlightFieldBuilder/$factoryGroup-$field-exp.expected",
					$factoryGroup,
					$field,
					$configExp
				];
			}
		}
		return $tests;
	}

	/**
	 * @dataProvider provideNewHighlightFieldWithFactory
	 */
	public function testNewHighlightFieldWithFactory( $expectedFile, $factoryGroup, $fieldName, $config ) {
		$actualField = ( new FetchPhaseConfigBuilder( $config, $factoryGroup ) )
			->newHighlightField( $fieldName, 'myTarget', 123 );
		$this->assertFileContains( $expectedFile, CirrusTestCase::encodeFixture( $actualField->toArray() ),
			CirrusTestCase::canRebuildFixture() );
	}

	public function testAddField() {
		$builder = new FetchPhaseConfigBuilder( new HashSearchConfig( [] ) );
		$field = $builder->newHighlightField( 'my_field', 'my_target', 123 );
		$builder->addHLField( $field );
		$this->assertSame( $field, $builder->getHLField( 'my_field' ) );
		$field2 = $builder->newHighlightField( 'my_field', 'my_target', 123 );
		try {
			$builder->addHLField( $field2 );
			$this->fail( 'merge must be called when adding a new field' );
		} catch ( \InvalidArgumentException $iae ) {
			$this->assertContains( 'must have a query', $iae->getMessage() );
		}
	}

	public function testNewRegexField() {
		$configDef = new HashSearchConfig( [ 'CirrusSearchUseExperimentalHighlighter' => false ] );
		$builder = new FetchPhaseConfigBuilder( $configDef );
		$builder->addNewRegexHLField( 'test', 'target', '(foo|bar)', false, 123 );
		$f = $builder->getHLField( 'test' );
		$this->assertNull( $f );

		$configExp = new HashSearchConfig( [ 'CirrusSearchUseExperimentalHighlighter' => true ] );
		$builder = new FetchPhaseConfigBuilder( $configExp );
		$builder->addNewRegexHLField( 'test', 'target', '(foo|bar)', false, 123 );
		$f = $builder->getHLField( 'test' );
		$this->assertNotNull( $f );
		$builder->addNewRegexHLField( 'test', 'target', '(baz|bat)', true, 123 );
		$this->assertNotNull( $f );
		$this->assertArrayHasKey( 'regex', $f->getOptions() );
		$this->assertEquals( [ '(foo|bar)', '(baz|bat)' ], $f->getOptions()['regex'] );
	}

	public function testGetHighlightConfig() {
		$config = new HashSearchConfig( [ 'CirrusSearchFragmentSize' => 123 ] );
		$builder = new FetchPhaseConfigBuilder( $config, SearchQuery::SEARCH_TEXT );
		$f1 = $builder->newHighlightField( 'text', FetchedFieldBuilder::TARGET_MAIN_SNIPPET, 123 );
		$f2 = $builder->newHighlightField( 'auxiliary_text', FetchedFieldBuilder::TARGET_MAIN_SNIPPET, 123 );
		$builder->addHLField( $f1 );
		$builder->addHLField( $f2 );

		$this->assertEquals(
			[
				'pre_tags' => [ Searcher::HIGHLIGHT_PRE_MARKER ],
				'post_tags' => [ Searcher::HIGHLIGHT_POST_MARKER ],
				'fields' => [ $f1->getFieldName() => $f1->toArray(), $f2->getFieldName() => $f2->toArray() ],
				'highlight_query' => ( new MatchAll() )->toArray()
			],
			$builder->buildHLConfig( new MatchAll() )
		);

		$this->assertEquals(
			[
				'pre_tags' => [ Searcher::HIGHLIGHT_PRE_MARKER ],
				'post_tags' => [ Searcher::HIGHLIGHT_POST_MARKER ],
				'fields' => [ $f1->getFieldName() => $f1->toArray(), $f2->getFieldName() => $f2->toArray() ],
			],
			$builder->buildHLConfig()
		);
	}
}
