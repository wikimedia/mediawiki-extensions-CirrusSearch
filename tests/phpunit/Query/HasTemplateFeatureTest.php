<?php

namespace CirrusSearch\Query;

use CirrusSearch\CrossSearchStrategy;

/**
 * @covers \CirrusSearch\Query\HasTemplateFeature
 * @group CirrusSearch
 */
class HasTemplateFeatureTest extends BaseSimpleKeywordFeatureTest {

	public function parseProvider() {
		return [
			'basic usage' => [
				[ 'match' => [
					'template' => [
						'query' => 'Template:Coord',
					],
				] ],
				[ 'value' => 'Template:Coord' ],
				'hastemplate:Coord',
			],
			'calling out Template NS directly' => [
				[ 'match' => [
					'template' => [
						'query' => 'Template:Coord',
					],
				] ],
				[ 'value' => 'Template:Coord' ],
				'hastemplate:Template:Coord',
			],
			'with namespace' => [
				[ 'match' => [
					'template' => [
						'query' => 'User talk:Zomg',
					],
				] ],
				[ 'value' => 'User_talk:Zomg' ],
				'hastemplate:User_talk:Zomg',
			],
			'using colon prefix to indicate NS_MAIN' => [
				[ 'match' => [
					'template' => [
						'query' => 'Main page',
					],
				] ],
				[ 'value' => 'Main_page' ],
				'hastemplate::Main_page',
			],
		];
	}

	/**
	 * @dataProvider parseProvider
	 */
	public function testParse( array $expected, array $expectedParsedValue, $term ) {
		$feature = new HasTemplateFeature();
		$this->assertParsedValue( $feature, $term, $expectedParsedValue, [] );
		$this->assertCrossSearchStrategy( $feature, $term, CrossSearchStrategy::allWikisStrategy() );
		$this->assertExpandedData( $feature, $term, [], [] );
		$this->assertFilter( $feature, $term, $expected, [] );
	}
}
