<?php

namespace CirrusSearch\Query;

use CirrusSearch\Search\SearchContext;

class SimpleKeywordFeatureTest extends \PHPUnit_Framework_TestCase {
	public function applyProvider() {
		return array(
			'unquoted value' => array(
				// expected doApply calls
				array(
					array( 'mock', 'unquoted', 'unquoted', false ),
				),
				// expected remaining term
				'',
				// input term
				'mock:unquoted'
			),
			'quoted value' => array(
				// expected doApply calls
				array(
					array( 'mock', 'some stuff', '"some stuff"', false ),
				),
				// expected remaining term
				'',
				// input term
				'mock:"some stuff"'
			),
			'quoted value with escaped quotes' => array(
				// expected doApply calls
				array(
					array( 'mock', 'some "stuff"', '"some \\"stuff\\""', false ),
				),
				// expected remaining term
				'',
				// input term
				'mock:"some \\"stuff\\""'
			),
			'quoted value wrapped whole in escaped quotes' => array(
				array(
					array( 'mock', '"some stuff"', '"\\"some stuff\\""', false ),
				),
				// expected remaining term
				'',
				// input term
				'mock:"\\"some stuff\\""',
			),
			'keyword doesnt have to be a prefix' => array(
				// expected doApply calls
				array(
					array( 'mock', 'stuff', 'stuff', false ),
				),
				// expected remaining term
				'unrelated ',
				// input term
				'unrelated mock:stuff',
			),
			'multiple keywords' => array(
				// expected doApply calls
				array(
					array( 'mock', 'foo', 'foo', false ),
					array( 'mock', 'bar', '"bar"', false ),
				),
				// expected remaining term
				'extra pieces ',
				// input term
				'extra mock:foo pieces mock:"bar"'
			),
			'negation' => array(
				// expected doApply calls
				array(
					array( 'mock', 'things', 'things', true ),
				),
				// expected remaining term
				'',
				// input term
				'-mock:things'
			),
			'handles space between keyword and value' => array(
				// expected doApply calls
				array(
					array( 'mock', 'value', 'value', false ),
				),
				// expected remaining term
				'',
				//input term
				'mock: value',
			),
			'eats single extra space after the value' => array(
				// expected doApply calls
				array(
					array( 'mock', 'value', 'value', false ),
				),
				// expected remaining term
				'unrelated',
				// input term
				'mock:value unrelated',
			),
			'doesnt trigger on prefixed keyword' => array(
				// expected doApply calls
				array(),
				// expected remaining term
				'somemock:value',
				// input term
				'somemock:value',
			),
			'doesnt trigger on prefixed keyword with term before it' => array(
				// expected doApply calls
				array(),
				// expected remaining term
				'foo somemock:value',
				// input term
				'foo somemock:value',
			),
			'doesnt get confused with empty quoted value' => array(
				// expected doApply calls
				array(
					array( 'mock', '', '""', false ),
				),
				// expected remaining term
				'links to catapult""',
				// input term
				'mock:"" links to catapult""',
			),
			'doesnt get confused with empty quoted value missing trailing space' => array(
				// expected doApply calls
				array(
					array( 'mock', '', '""', false ),
				),
				// expected remaining term
				'links to catapult""',
				// input term
				'mock:""links to catapult""',
			),
			'treats closing quote as end of value' => array(
				array(
					array( 'mock', 'foo', '"foo"', false ),
				),
				'links to catapult',
				'mock:"foo"links to catapult',
			),
			'odd but expected handling of single escaped quote' => array(
				array(
					array( 'mock', '\\', '\\', false ),
				),
				'"foo',
				'mock:\"foo'
			),
			'appropriate way to pass single escaped quote if needed' => array(
				array(
					array( 'mock', '"foo', '"\\"foo"', false ),
				),
				'',
				'mock:"\"foo"',
			),
		);
	}
	/**
	 * @dataProvider applyProvider
	 */
	public function testApply( $expectedArgs, $expectedTerm, $term ) {
		$context = $this->getMockBuilder( SearchContext::class )
			->disableOriginalConstructor()
			->getMock();

		$feature = new MockSimpleKeywordFeature();
		$this->assertEquals(
			$expectedTerm,
			$feature->apply( $context, $term )
		);

		$this->assertEquals( $expectedArgs, $feature->getApplyCallArguments() );
	}
}

class MockSimpleKeywordFeature extends SimpleKeywordFeature {
	private $calls = array();

	protected function getKeywordRegex() {
		return 'mock';
	}

	protected function doApply( SearchContext $context, $key, $value, $quotedValue, $negated ) {
		$this->calls[] = array( $key, $value, $quotedValue, $negated );
	}

	public function getApplyCallArguments() {
		return $this->calls;
	}
}
