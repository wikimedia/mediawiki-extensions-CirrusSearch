<?php

namespace CirrusSearch\Query;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\CrossSearchStrategy;
use Elastica\Query\MultiMatch;

/**
 * @covers \CirrusSearch\Query\SubPageOfFeature
 * @group CirrusSearch
 */
class SubPageOfFeatureTest extends CirrusTestCase {
	use SimpleKeywordFeatureTestTrait;

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
			],
			'allow wildcard to act as classic prefix query' => [
				'subpageof:"test*"',
				'test'
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
		$this->assertExpandedData( $feature, $query, [], [] );
		$this->assertCrossSearchStrategy( $feature, $query, CrossSearchStrategy::allWikisStrategy() );
		$filterCallback = null;
		if ( $filterValue !== null ) {
			$this->assertParsedValue( $feature, $query, [ 'prefix' => $filterValue ], [] );
			$filterCallback = function ( MultiMatch $match ) use ( $filterValue ) {
				$this->assertEquals( [ 'title.prefix', 'redirect.title.prefix' ],
					$match->getParam( 'fields' ), "fields of the multimatch query should match",
					0.0, 10, true );
				$this->assertEquals( $filterValue, $match->getParam( 'query' ) );
				return true;
			};
		} else {
			$this->assertParsedValue( $feature, $query, null );
		}
		$this->assertFilter( $feature, $query, $filterCallback, [] );
	}
}
