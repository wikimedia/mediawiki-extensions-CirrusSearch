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
				'hastemplate:Coord',
			],
			'calling out Template NS directly' => [
				[ 'match' => [
					'template' => [
						'query' => 'Template:Coord',
					],
				] ],
				'hastemplate:Template:Coord',
			],
			'with namespace' => [
				[ 'match' => [
					'template' => [
						'query' => 'User talk:Zomg',
					],
				] ],
				'hastemplate:User_talk:Zomg',
			],
			'using colon prefix to indicate NS_MAIN' => [
				[ 'match' => [
					'template' => [
						'query' => 'Main page',
					],
				] ],
				'hastemplate::Main_page',
			],
		];
	}

	/**
	 * @dataProvider parseProvider
	 */
	public function testParse( array $expected, $term ) {
		$context = $this->mockContextExpectingAddFilter( $expected );
		$feature = new HasTemplateFeature();
		$this->assertCrossSearchStrategy( $feature, $term, CrossSearchStrategy::allWikisStrategy() );
		$feature->apply( $context, $term );
	}
}
