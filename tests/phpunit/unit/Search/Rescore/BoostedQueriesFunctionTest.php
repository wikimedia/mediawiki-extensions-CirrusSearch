<?php

namespace CirrusSearch\Search\Rescore;

use CirrusSearch\CirrusTestCase;
use Elastica\Query\BoolQuery;
use Elastica\Query\FunctionScore;
use Elastica\Query\Term;

/**
 * @covers \CirrusSearch\Search\Rescore\BoostedQueriesFunction
 */
class BoostedQueriesFunctionTest extends CirrusTestCase {

	public function provideTestData() {
		$termQuery = new Term( [ 'field' => 'term' ] );
		$notTermQuery = new BoolQuery();
		$notTermQuery->addMustNot( $termQuery );
		return [
			'single positive query' => [
				[ $termQuery ],
				[ 0.3 ],
				[
					[
						'weight' => 0.3,
						'filter' => $termQuery->toArray()
					]
				]
			],
			'two positive queries' => [
				[ $termQuery, $termQuery ],
				[ 0.3, 0.5 ],
				[
					[
						'weight' => 0.3,
						'filter' => $termQuery->toArray()
					],
					[
						'weight' => 0.5,
						'filter' => $termQuery->toArray()
					]
				]
			],
			'single negative query' => [
				[ $termQuery ],
				[ -0.3 ],
				[
					[
						'weight' => 0.3,
						'filter' => $notTermQuery->toArray()
					]
				]
			],
			'one negative query and a positive' => [
				[ $termQuery, $termQuery ],
				[ 0.3, -0.4 ],
				[
					[
						'weight' => 0.3,
						'filter' => $termQuery->toArray()
					],
					[
						'weight' => 0.4,
						'filter' => $notTermQuery->toArray()
					]
				]
			],
		];
	}

	/**
	 * @param Elastica\Query\AbstractQuery[] $queries
	 * @param float[] $weights
	 * @param array $expectedFunctions
	 * @dataProvider provideTestData
	 */
	public function testAppend( array $queries, array $weights, array $expectedFunctions ) {
		$boostedQuery = new BoostedQueriesFunction( $queries, $weights );
		$functionScore = new FunctionScore();
		$boostedQuery->append( $functionScore );
		$functionScoreArray = $functionScore->toArray();
		$this->assertArrayHasKey( 'function_score', $functionScoreArray );
		$this->assertArrayHasKey( 'functions', $functionScoreArray['function_score'] );
		$actualFunctions = $functionScoreArray['function_score']['functions'];
		$this->assertArrayEquals( $expectedFunctions, $actualFunctions );
	}
}
