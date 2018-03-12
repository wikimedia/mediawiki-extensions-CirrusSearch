<?php

namespace CirrusSearch\Query;

/**
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
		$feature->apply( $context, $term );
	}
}
