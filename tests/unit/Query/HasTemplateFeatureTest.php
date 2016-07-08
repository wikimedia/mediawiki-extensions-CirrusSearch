<?php

namespace CirrusSearch\Query;

class HasTemplateFeatureText extends BaseSimpleKeywordFeatureTest {

	public function parseProvider() {
		return array(
			'basic usage' => array(
				array( 'match' => array(
					'template' => array(
						'query' => 'Template:Coord',
					),
				) ),
				'hastemplate:Coord',
			),
			'calling out Template NS directly' => array(
				array( 'match' => array(
					'template' => array(
						'query' => 'Template:Coord',
					),
				) ),
				'hastemplate:Template:Coord',
			),
			'with namespace' => array(
				array( 'match' => array(
					'template' => array(
						'query' => 'User talk:Zomg',
					),
				) ),
				'hastemplate:User_talk:Zomg',
			),
			'using colon prefix to indicate NS_MAIN' => array(
				array( 'match' => array(
					'template' => array(
						'query' => 'Main page',
					),
				) ),
				'hastemplate::Main_page',
			),
		);
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
