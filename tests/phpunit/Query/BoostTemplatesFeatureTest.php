<?php

namespace CirrusSearch\Query;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\SearchConfig;
use CirrusSearch\Search\SearchContext;

/**
 * @group CirrusSearch
 */
class BoostTemplatesFeatureTest extends CirrusTestCase {

	public function parseProvider() {
		return [
			'single template parse' => [
				[ 'Main article' => 2.5 ],
				'boost-templates:"Main article|250%"',
			],
			'multiple template parse' => [
				[ 'Featured article' => 1.75, 'Main article' => 1.50 ],
				'boost-templates:"Featured article|175% Main article|150%"',
			],
			'converts underscores to match indexing' => [
				[ 'Main article' => 1.23 ],
				'boost-templates:Main_article|123%',
			],
			'deboost' => [
				[ 'Thing' => 0.01 ],
				'boost-templates:Thing|1%'
			],
		];
	}

	/**
	 * @dataProvider parseProvider
	 */
	public function testParse( $expect, $term ) {
		$config = $this->getMock( SearchConfig::class );
		$context = new SearchContext( $config );

		$feature = new BoostTemplatesFeature();
		$feature->apply( $context, $term );

		$this->assertEquals(
			$expect,
			$context->getBoostTemplatesFromQuery()
		);
	}
}
