<?php

namespace CirrusSearch\Query;

use CirrusSearch\CrossSearchStrategy;
use Elastica\Query\MultiMatch;

/**
 * @covers \CirrusSearch\Query\SubPageOfFeature
 * @group CirrusSearch
 */
class SubPageOfFeatureTest extends BaseSimpleKeywordFeatureTest {

	public function provideQueries() {
		return [
			'simple' => [
				'subpageof:test',
				'test/'
			],
			'simple quoted' => [
				'subpageof:"test hello"',
				'test hello/'
			],
			'simple quoted with trailing /' => [
				'subpageof:"test hello/"',
				'test hello/'
			],
			'simple empty' => [
				'subpageof:""',
				null,
			]
		];
	}

	/**
	 * @dataProvider provideQueries()
	 * @param $query
	 * @param $filterValue
	 */
	public function test( $query, $filterValue ) {
		$feature = new SubPageOfFeature();
		$this->assertParsedValue( $feature, $query, null, [] );
		$this->assertExpandedData( $feature, $query, [], [] );
		$this->assertCrossSearchStrategy( $feature, $query, CrossSearchStrategy::allWikisStrategy() );
		$filterCallback = null;
		if ( $filterValue !== null ) {
			$filterCallback = function ( MultiMatch $match ) use ( $filterValue ) {
				$this->assertArrayEquals( [ 'title.prefix', 'redirect.title.prefix' ],
					$match->getParam( 'fields' ) );
				$this->assertEquals( $filterValue, $match->getParam( 'query' ) );
				return true;
			};
		}
		$this->assertFilter( $feature, $query, $filterCallback, [] );
	}
}
