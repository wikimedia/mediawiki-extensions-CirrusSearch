<?php

namespace CirrusSearch\Query;

use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\LoadBalancer;

/**
 * @group CirrusSearch
 */
class InCategoryFeatureTest extends BaseSimpleKeywordFeatureTest {

	public function parseProvider() {
		return [
			'single category' => [
				[ 'bool' => [
					'should' => [
						[ 'match' => [
							'category.lowercase_keyword' => [
								'query' => 'Zomg',
							],
						] ]
					]
				] ],
				'incategory:Zomg'
			],
			'multiple categories' => [
				[ 'bool' => [
					'should' => [
						[ 'match' => [
							'category.lowercase_keyword' => [
								'query' => 'Zomg',
							],
						] ],
						[ 'match' => [
							'category.lowercase_keyword' => [
								'query' => 'Wowzers',
							],
						] ]
					]
				] ],
				'incategory:Zomg|Wowzers'
			],
			'resolves id: prefix' => [
				[ 'bool' => [
					'should' => [
						[ 'match' => [
							'category.lowercase_keyword' => [
								'query' => 'Cat2',
							],
						] ],
					]
				] ],
				'incategory:id:2',
			],
			'throws away invalid id: values' => [
				null,
				'incategory:id:qwerty',
			],
			'throws away unknown id: values' => [
				null,
				'incategory:id:7654321'
			],
			'allows mixing id: with names' => [
				[ 'bool' => [
					'should' => [
						[ 'match' => [
							'category.lowercase_keyword' => [
								'query' => 'Cirrus',
							],
						] ],
						[ 'match' => [
							'category.lowercase_keyword' => [
								'query' => 'Cat2',
							],
						] ],
					],
				] ],
				'incategory:Cirrus|id:2',
			],
			'applies supplied category limit' => [
				[ 'bool' => [
					'should' => [
						[ 'match' => [
							'category.lowercase_keyword' => [
								'query' => 'This',
							],
						] ],
						[ 'match' => [
							'category.lowercase_keyword' => [
								'query' => 'That',
							],
						] ]
					]
				] ],
				'incategory:This|That|Other',
			],
			'invalid id: counts towards category limit' => [
				[ 'bool' => [
					'should' => [
						[ 'match' => [
							'category.lowercase_keyword' => [
								'query' => 'Test',
							],
						] ],
					]
				] ],
				'incategory:id:qwerty|Test|Case',
			],
		];
	}

	/**
	 * @dataProvider parseProvider
	 */
	public function testParse( array $expected = null, $term ) {
		$this->mockDB();

		$context = $this->mockContextExpectingAddFilter( $expected );
		$context->expects( $this->exactly(
				$expected === null ? 1 : 0
			) )
			->method( 'setResultsPossible' )
			->with( false );

		$feature = new InCategoryFeature( new \HashConfig( [
			'CirrusSearchMaxIncategoryOptions' => 2,
		] ) );
		$feature->apply( $context, $term );
	}

	/**
	 * Injects a database that knows about a fake page with id of 2
	 * for use in test cases.
	 */
	private function mockDB() {
		$db = $this->getMock( IDatabase::class );
		$db->expects( $this->any() )
			->method( 'select' )
			->with( 'page' )
			->will( $this->returnCallback( function ( $table, $select, $where ) {
				if ( isset( $where['page_id'] ) && $where['page_id'] === [ '2' ] ) {
					return [ (object)[
						'page_namespace' => NS_CATEGORY,
						'page_title' => 'Cat2',
						'page_id' => 2,
					] ];
				} else {
					return [];
				}
			} ) );
		$lb = $this->getMockBuilder( LoadBalancer::class )
			->disableOriginalConstructor()
			->getMock();
		$lb->expects( $this->any() )
			->method( 'getConnection' )
			->will( $this->returnValue( $db ) );
		$this->setService( 'DBLoadBalancer', $lb );
	}

	public function testTooManyCategoriesWarning() {
		$this->assertWarnings(
			new InCategoryFeature( new \HashConfig( [
				'CirrusSearchMaxIncategoryOptions' => 2,
			] ) ),
			[ [ 'cirrussearch-feature-too-many-conditions', 'incategory', 2 ] ],
			'incategory:a|b|c'
		);
	}

	public function testCategoriesMustExistWarning() {
		$this->assertWarnings(
			new InCategoryFeature( new \HashConfig( [
				'CirrusSearchMaxIncategoryOptions' => 2,
			] ) ),
			[ [ 'cirrussearch-incategory-feature-no-valid-categories', 'incategory' ] ],
			'incategory:id:74,id:18'
		);
	}
}
