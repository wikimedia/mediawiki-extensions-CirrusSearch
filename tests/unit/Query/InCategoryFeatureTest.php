<?php

namespace CirrusSearch\Query;

use LoadBalancer;
use IDatabase;

class InCategoryFeatureText extends BaseSimpleKeywordFeatureTest {

	public function parseProvider() {
		return array(
			'single category' => array(
				array( 'bool' => array(
					'should' => array(
						array( 'match' => array(
							'category.lowercase_keyword' => array(
								'query' => 'Zomg',
							),
						) )
					)
				) ),
				'incategory:Zomg'
			),
			'multiple categories' => array(
				array( 'bool' => array(
					'should' => array(
						array( 'match' => array(
							'category.lowercase_keyword' => array(
								'query' => 'Zomg',
							),
						) ),
						array( 'match' => array(
							'category.lowercase_keyword' => array(
								'query' => 'Wowzers',
							),
						) )
					)
				) ),
				'incategory:Zomg|Wowzers'
			),
			'resolves id: prefix' => array(
				array( 'bool' => array(
					'should' => array(
						array( 'match' => array(
							'category.lowercase_keyword' => array(
								'query' => 'Cat2',
							),
						) ),
					)
				) ),
				'incategory:id:2',
			),
			'throws away invalid id: values' => array(
				null,
				'incategory:id:qwerty',
			),
			'throws away unknown id: values' => array(
				null,
				'incategory:id:7654321'
			),
			'allows mixing id: with names' => array(
				array( 'bool' => array(
					'should' => array(
						array( 'match' => array(
							'category.lowercase_keyword' => array(
								'query' => 'Cirrus',
							),
						) ),
						array( 'match' => array(
							'category.lowercase_keyword' => array(
								'query' => 'Cat2',
							),
						) ),
					),
				) ),
				'incategory:Cirrus|id:2',
			),
			'applies supplied category limit' => array(
				array( 'bool' => array(
					'should' => array(
						array( 'match' => array(
							'category.lowercase_keyword' => array(
								'query' => 'This',
							),
						) ),
						array( 'match' => array(
							'category.lowercase_keyword' => array(
								'query' => 'That',
							),
						) )
					)
				) ),
				'incategory:This|That|Other',
			),
			'invalid id: counts towards category limit' => array(
				array( 'bool' => array(
					'should' => array(
						array( 'match' => array(
							'category.lowercase_keyword' => array(
								'query' => 'Test',
							),
						) ),
					)
				) ),
				'incategory:id:qwerty|Test|Case',
			),
		);
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

		$feature = new InCategoryFeature( new \HashConfig( array(
			'CirrusSearchMaxIncategoryOptions' => 2,
		) ) );
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
				if ( isset( $where['page_id'] ) && $where['page_id'] === array( '2' ) ) {
					return array( (object) array(
						'page_namespace' => NS_CATEGORY,
						'page_title' => 'Cat2',
						'page_id' => 2,
					) );
				} else {
					return array();
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
}
