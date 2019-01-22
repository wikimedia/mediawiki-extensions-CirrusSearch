<?php

namespace CirrusSearch\Fallbacks;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\DummyResultSet;
use CirrusSearch\Search\ResultSet;
use CirrusSearch\Search\SearchQuery;
use CirrusSearch\Searcher;

class BaseFallbackMethodTest extends CirrusTestCase {

	public function getSearcherFactoryMock( SearchQuery $query = null, ResultSet $resultSet = null ) {
		$searcherMock = $this->createMock( Searcher::class );
		$searcherMock->expects( $query != null ? $this->once() : $this->never() )
			->method( 'search' )
			->with( $query )
			->willReturn( $resultSet === null ? \Status::newFatal( 'Error' ) : \Status::newGood( $resultSet ) );
		$searcherMock->expects( $query != null ? $this->atMost( 1 ) : $this->never() )
			->method( 'getSearchMetrics' )
			->willReturn( [ 'searcherMetrics' => 'called' ] );

		$mock = $this->createMock( SearcherFactory::class );
		$mock->expects( $this->any() )
			->method( 'makeSearcher' )
			->willReturn( $searcherMock );
		return $mock;
	}

	public function provideTestResultThreshold() {
		return [
			'threshold is not reached' => [
				1,
				0,
				[],
				false
			],
			'threshold is not reached even with interwiki results' => [
				1,
				0,
				[ 0, 0 ],
				false,
			],
			'threshold is reached' => [
				1,
				1,
				[],
				true
			],
			'threshold is reached reading interwiki results' => [
				1,
				0,
				[ 0, 1, 0 ],
				true
			],
			'threshold can be greater than 1 and not reached' => [
				3,
				2,
				[],
				false
			],
			'threshold can be greater than 1 and not reached with interwiki results' => [
				3,
				0,
				[ 0, 2, 0 ],
				false,
			],
			'threshold can be greater than 1 and reached' => [
				3,
				3,
				[],
				true
			],
			'threshold can be greater than 1 and reached with interwiki results' => [
				3,
				0,
				[ 0, 3, 0 ],
				true,
			],
			'threshold can be greater than 1 and exceeded' => [
				3,
				5,
				[],
				true
			],
			'threshold can be greater than 1 and exceeded with interwiki results' => [
				3,
				0,
				[ 0, 5, 0 ],
				true,
			],
		];
	}

	/**
	 * @dataProvider provideTestResultThreshold
	 * @covers \CirrusSearch\Fallbacks\FallbackMethodTrait::resultsThreshold()
	 */
	public function testResultThreshold( $threshold, $mainTotal, array $interwikiTotals, $met ) {
		$resultSet = DummyResultSet::fakeTotalHits( $mainTotal, $interwikiTotals );
		$mock = $this->getMockForTrait( FallbackMethodTrait::class );
		$this->assertEquals( $met, $mock->resultsThreshold( $resultSet, $threshold ) );
		if ( $threshold === 1 ) {
			// Test default method param
			$this->assertEquals( $mock->resultsThreshold( $resultSet ),
				$mock->resultsThreshold( $resultSet, $threshold ) );
		}
	}
}
