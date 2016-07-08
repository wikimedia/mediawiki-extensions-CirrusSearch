<?php

namespace CirrusSearch\Query;

use CirrusSearch\SearchConfig;
use CirrusSearch\Search\SearchContext;

class BoostTemplatesFeatureTest extends \PHPUnit_Framework_TestCase {

	public function parseProvider() {
		return array(
			'single template parse' => array(
				array( 'Main article' => 2.5 ),
				'boost-templates:"Main article|250%"',
			),
			'multiple template parse' => array(
				array( 'Featured article' => 1.75, 'Main article' => 1.50 ),
				'boost-templates:"Featured article|175% Main article|150%"',
			),
			'converts underscores to match indexing' => array(
				array( 'Main article' => 1.23 ),
				'boost-templates:Main_article|123%',
			),
			'deboost' => array(
				array( 'Thing' => 0.01 ),
				'boost-templates:Thing|1%'
			),
		);
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
