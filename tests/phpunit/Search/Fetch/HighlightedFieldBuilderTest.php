<?php

namespace CirrusSearch\Search\Fetch;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Search\SearchQuery;
use CirrusSearch\SearchConfig;
use Elastica\Query\BoolQuery;
use Elastica\Query\MatchAll;

/**
 * @covers \CirrusSearch\Search\Fetch\FetchedFieldBuilder
 * @covers \CirrusSearch\Search\Fetch\BaseHighlightedFieldBuilder
 * @covers \CirrusSearch\Search\Fetch\ExperimentalHighlightedFieldBuilder
 */
class HighlightedFieldBuilderTest extends CirrusTestCase {
	public function provideTestFactories() {
		$tests = [];
		$config = new HashSearchConfig( [
			'CirrusSearchFragmentSize' => 350,
		] );
		$baseFactories = BaseHighlightedFieldBuilder::getFactories();
		$expFactories = ExperimentalHighlightedFieldBuilder::getFactories();
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
					$baseFactories,
					$factoryGroup,
					$field,
					$config
				];
				$tests["$factoryGroup-$field-exp"] = [
					CirrusTestCase::FIXTURE_DIR . "/highlightFieldBuilder/$factoryGroup-$field-exp.expected",
					$expFactories,
					$factoryGroup,
					$field,
					$config
				];
			}
		}
		return $tests;
	}

	/**
	 * @dataProvider provideTestFactories
	 */
	public function testFactories( $expectedFile, $factories, $factoryGroup, $fieldName, SearchConfig $config ) {
		$this->assertArrayHasKey( $factoryGroup, $factories );
		$this->assertArrayHasKey( $fieldName, $factories[$factoryGroup] );
		$this->assertTrue( is_callable( $factories[$factoryGroup][$fieldName] ) );
		/** @var BaseHighlightedFieldBuilder $actualField */
		$actualField = ( $factories[$factoryGroup][$fieldName] ) ( $config, $fieldName, 'dummyTarget', 1234 );
		$this->assertFileContains( $expectedFile, CirrusTestCase::encodeFixture( $actualField->toArray() ),
			CirrusTestCase::canRebuildFixture() );
	}

	public function testSetters() {
		$expField = new ExperimentalHighlightedFieldBuilder( 'myfield', 'mytarget', 123 );
		$baseField = new BaseHighlightedFieldBuilder( 'myfield', BaseHighlightedFieldBuilder::FVH_HL_TYPE, 'mytarget', 123 );
		foreach ( [ $expField, $baseField ] as $field ) {
			/** @var $field BaseHighlightedFieldBuilder */
			$this->assertEquals( BaseHighlightedFieldBuilder::TYPE, $field->getType() );
			$this->assertEquals( 'myfield', $field->getFieldName() );
			$this->assertEquals( 'mytarget', $field->getTarget() );
			$this->assertEquals( 123, $field->getPriority() );
			$this->assertNull( $field->getOrder() );
			$field->setOrder( 'score' );
			$this->assertEquals( 'score', $field->getOrder() );

			$this->assertNull( $field->getHighlightQuery() );
			$field->setHighlightQuery( new MatchAll() );
			$this->assertEquals( new MatchAll(), $field->getHighlightQuery() );

			$this->assertEmpty( $field->getOptions() );
			$field->setOptions( [ 'foo' => 'bar', 'baz' => 'bat' ] );
			$field->addOption( 'foo', 'overwrittenBar' );
			$this->assertEquals( [ 'foo' => 'overwrittenBar', 'baz' => 'bat' ], $field->getOptions() );

			$this->assertNull( $field->getNoMatchSize() );
			$field->setNoMatchSize( 22 );
			$this->assertEquals( 22, $field->getNoMatchSize() );

			$this->assertNull( $field->getNumberOfFragments() );
			$field->setNumberOfFragments( 34 );
			$this->assertEquals( 34, $field->getNumberOfFragments() );

			$this->assertNull( $field->getFragmenter() );
			$field->setFragmenter( 'scan' );
			$this->assertEquals( 'scan', $field->getFragmenter() );

			$this->assertNull( $field->getFragmentSize() );
			$field->setFragmentSize( 45 );
			$this->assertEquals( 45, $field->getFragmentSize() );

			$this->assertEmpty( $field->getMatchedFields() );
			$field->addMatchedField( 'foo' );
			$field->addMatchedField( 'bar' );
			$this->assertEquals( [ 'foo', 'bar' ], $field->getMatchedFields() );
		}
	}

	public function testSkipIfLastMatched() {
		$expField = new ExperimentalHighlightedFieldBuilder( 'myfield', 'mytarget', 123 );
		$baseField = new BaseHighlightedFieldBuilder( 'myfield', 'mytarget', 123 );

		$expField->skipIfLastMatched();
		$this->assertEquals( [ 'skip_if_last_matched' => true ], $expField->getOptions() );

		$baseField->skipIfLastMatched();
		$this->assertEmpty( $baseField->getOptions() );
	}

	public function testRegex() {
		$config = new HashSearchConfig( [
			'CirrusSearchRegexMaxDeterminizedStates' => 233,
			'LanguageCode' => 'testLangCode',
			'CirrusSearchFragmentSize' => 345,
		] );
		$field = ExperimentalHighlightedFieldBuilder::newRegexField(
			$config,
			'testField',
			'testTarget',
			'(foo|bar)',
			false,
			456 );
		$options = $field->getOptions();
		$this->assertArrayHasKey( 'regex', $options );
		$this->assertArrayHasKey( 'regex_flavor', $options );
		$this->assertArrayHasKey( 'locale', $options );
		$this->assertArrayHasKey( 'skip_query', $options );
		$this->assertArrayHasKey( 'regex_case_insensitive', $options );
		$this->assertArrayHasKey( 'max_determinized_states', $options );
		$this->assertEquals( [ '(foo|bar)' ], $options['regex'] );
		$this->assertEquals( 'lucene', $options['regex_flavor'] );
		$this->assertEquals( 'testLangCode', $options['locale'] );
		$this->assertSame( true, $options['skip_query'] );
		$this->assertSame( false, $options['regex_case_insensitive'] );
		$this->assertEquals( 233, $options['max_determinized_states'] );
		$this->assertNull( $field->getNoMatchSize() );

		$field2 = ExperimentalHighlightedFieldBuilder::newRegexField(
			$config,
			'testField',
			'testTarget',
			'(baz|bat)',
			true,
			456 );

		$field = $field->merge( $field2 );
		$options = $field->getOptions();
		$this->assertEquals( [ '(foo|bar)', '(baz|bat)' ], $options['regex'] );
		$this->assertSame( true, $options['regex_case_insensitive'] );

		$field3 = ExperimentalHighlightedFieldBuilder::newRegexField(
			$config,
			'testField3',
			'testTarget',
			'(baz|bat)',
			true,
			456 );

		try {
			$field->merge( $field3 );
			$this->fail();
		} catch ( \InvalidArgumentException $iae ) {
		}

		// Test a hack where we forcibly keep the regex even if we have the same field to highlight
		// Usecase is: insource:test insource:/test/
		// Without proper priority management we need to force keep the regex over the simple insource:word highlight
		$initialField = new ExperimentalHighlightedFieldBuilder( 'testField', 'testTarget', 2 );
		$initialField = $initialField->merge( $field );
		$this->assertSame( $field, $initialField );
		$initialField = $field->merge( $initialField );
		$this->assertSame( $field, $initialField );

		$sourcePlainSpecial = ExperimentalHighlightedFieldBuilder::newRegexField(
			$config,
			'source_text.plain',
			'testTarget',
			'(foo|bar)',
			true,
			456 );
		$this->assertEquals( 345, $sourcePlainSpecial->getNoMatchSize() );
	}

	public function testMerge() {
		$fields = [
			[
				new BaseHighlightedFieldBuilder( 'test', BaseHighlightedFieldBuilder::FVH_HL_TYPE, 'test', 123 ),
				new BaseHighlightedFieldBuilder( 'test', BaseHighlightedFieldBuilder::FVH_HL_TYPE, 'test', 123 )
			],
			[
				new ExperimentalHighlightedFieldBuilder( 'test', 'test', 123 ),
				new ExperimentalHighlightedFieldBuilder( 'test', 'test', 123 )
			],
		];
		foreach ( $fields as $couple ) {
			list( $field1, $field2 ) = $couple;
			$field1->setHighlightQuery( new MatchAll() );
			$field2->setHighlightQuery( new MatchAll() );
			$field1 = $field1->merge( $field2 );
			$expectedQuery = new BoolQuery();
			$expectedQuery->addShould( new MatchAll() );
			$expectedQuery->addShould( new MatchAll() );
			$this->assertEquals( $expectedQuery, $field1->getHighlightQuery() );

			$expectedQuery->addShould( new MatchAll() );
			$field1->merge( $field2 );
			$this->assertEquals( $expectedQuery, $field1->getHighlightQuery() );
		}
	}

	public function testMergeGuards() {
		$this->assertMergeFailure(
			new BaseHighlightedFieldBuilder( 'field1', 'hltype', 'target', 123 ),
			new BaseHighlightedFieldBuilder( 'field2', 'hltype', 'target', 123 ),
			"HL Field [field1] must have the same field name to be mergeable with [field2]" );

		$this->assertMergeFailure(
			new BaseHighlightedFieldBuilder( 'field1', 'hltype', 'target', 123 ),
			new BaseHighlightedFieldBuilder( 'field1', 'hltype2', 'target', 123 ),
			"HL Field [field1] must have the same highlighterType to be mergeable" );

		$this->assertMergeFailure(
			new BaseHighlightedFieldBuilder( 'field1', 'hltype', 'target', 123 ),
			new BaseHighlightedFieldBuilder( 'field1', 'hltype', 'target2', 123 ),
			"HL Field [field1] must have the same target to be mergeable" );

		$fieldCouples = [
			[
				new BaseHighlightedFieldBuilder( 'test', 'hltype', 'target', 123 ),
				new BaseHighlightedFieldBuilder( 'test', 'hltype', 'target', 124 )
			],
			[
				new ExperimentalHighlightedFieldBuilder( 'test', 'target', 123 ),
				new ExperimentalHighlightedFieldBuilder( 'test', 'target', 124 )
			],
		];

		foreach ( $fieldCouples as $couple ) {
			/** @var BaseHighlightedFieldBuilder $f1 */
			/** @var BaseHighlightedFieldBuilder $f2 */
			list( $f1, $f2 ) = $couple;
			$this->assertMergeFailure( $f1, $f2, 'HL Field [test] must have a query to be mergeable' );
			$f1->setHighlightQuery( new MatchAll() );
			$this->assertMergeFailure( $f1, $f2, 'HL Field [test] must have a query to be mergeable' );
			$f2->setHighlightQuery( new MatchAll() );

			$f1->addMatchedField( 'foo' );
			$this->assertMergeFailure( $f1, $f2, 'HL Field [test] must have the same matchedFields to be mergeable' );
			$f2->addMatchedField( 'foo' );

			$f1->setFragmenter( 'foo' );
			$this->assertMergeFailure( $f1, $f2, 'HL Field [test] must have the same fragmenter to be mergeable' );
			$f2->setFragmenter( 'foo' );

			$f1->setNumberOfFragments( 3 );
			$this->assertMergeFailure( $f1, $f2, 'HL Field [test] must have the same numberOfFragments to be mergeable' );
			$f2->setNumberOfFragments( 3 );

			$f1->setNoMatchSize( 123 );
			$this->assertMergeFailure( $f1, $f2, 'HL Field [test] must have the same noMatchSize to be mergeable' );
			$f2->setNoMatchSize( 123 );

			$f1->setOptions( [ 'foo' => 'bar' ] );
			$this->assertMergeFailure( $f1, $f2, 'HL Field [test] must have the same options to be mergeable' );
			$f2->setOptions( [ 'foo' => 'bar' ] );

			$this->assertSame( $f1, $f1->merge( $f2 ) );
		}
	}

	public function assertMergeFailure( BaseHighlightedFieldBuilder $f1, BaseHighlightedFieldBuilder $f2, $msg ) {
		try {
			$f1->merge( $f2 );
			$this->fail( "Expected InvalidArumentException with message $msg" );
		} catch ( \InvalidArgumentException $iae ) {
			$this->assertContains( $msg, $iae->getMessage() );
		}
	}
}
