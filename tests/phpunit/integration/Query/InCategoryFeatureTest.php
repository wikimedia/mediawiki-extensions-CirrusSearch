<?php

namespace CirrusSearch\Query;

use ArrayIterator;
use CirrusSearch\CirrusIntegrationTestCase;
use CirrusSearch\CrossSearchStrategy;
use CirrusSearch\HashSearchConfig;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Page\PageSelectQueryBuilder;
use MediaWiki\Page\PageStore;
use MediaWiki\Page\PageStoreRecord;

/**
 * @covers \CirrusSearch\Query\InCategoryFeature
 * @group CirrusSearch
 */
class InCategoryFeatureTest extends CirrusIntegrationTestCase {
	use SimpleKeywordFeatureTestTrait;

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
				[],
				'incategory:Zomg',
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
				[],
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
				[],
				'incategory:id:2',
			],
			'throws away invalid id: values' => [
				null,
				[ [ 'cirrussearch-incategory-feature-no-valid-categories', 'incategory' ] ],
				'incategory:id:qwerty',
			],
			'throws away unknown id: values' => [
				null,
				[ [ 'cirrussearch-incategory-feature-no-valid-categories', 'incategory' ] ],
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
				[],
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
				[ [ 'cirrussearch-feature-too-many-conditions', 'incategory', 2 ] ],
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
				[ [ 'cirrussearch-feature-too-many-conditions', 'incategory', 2 ] ],
				'incategory:id:qwerty|Test|Case',
			],
		];
	}

	/**
	 * @dataProvider parseProvider
	 */
	public function testParse( ?array $expected, array $warnings, $term ) {
		$this->mockDB();
		$feature = new InCategoryFeature( new \HashConfig( [
			'CirrusSearchMaxIncategoryOptions' => 2,
		] ) );
		$this->assertFilter( $feature, $term, $expected, $warnings );
		if ( $expected === null ) {
			$this->assertNoResultsPossible( $feature, $term );
		}
	}

	public function testCrossSearchStrategy() {
		$feature = new InCategoryFeature( new HashSearchConfig( [] ) );

		$this->assertCrossSearchStrategy( $feature, "incategory:foo", CrossSearchStrategy::allWikisStrategy() );
		$this->assertCrossSearchStrategy( $feature, "incategory:foo|bar", CrossSearchStrategy::allWikisStrategy() );
		$this->assertCrossSearchStrategy( $feature, "incategory:id:123", CrossSearchStrategy::hostWikiOnlyStrategy() );
		$this->assertCrossSearchStrategy( $feature, "incategory:foo|id:123", CrossSearchStrategy::hostWikiOnlyStrategy() );
	}

	/**
	 * Injects a PageStore that knows about a fake page with id of 2
	 * for use in test cases.
	 */
	private function mockDB() {
		$pageStore = $this->createMock( PageStore::class );
		$pageSelectQueryBuilder = $this->getMockBuilder( PageSelectQueryBuilder::class )
			->setConstructorArgs(
				[
					$this->getServiceContainer()->getDBLoadBalancer()->getConnectionRef( DB_REPLICA ),
					$pageStore
				]
			)
			->onlyMethods( [ 'fetchPageRecords' ] )
			->getMock();

		$pageSelectQueryBuilder->method( 'fetchPageRecords' )->willReturnCallback(
			static function () use ( $pageSelectQueryBuilder ) {
				[ 'conds' => $conds ] = $pageSelectQueryBuilder->getQueryInfo();
				if ( isset( $conds['page_id'] ) && $conds['page_id'][0] == '2' ) {
					return new ArrayIterator( [
						new PageStoreRecord(
							(object)[
								'page_namespace' => NS_CATEGORY,
								'page_title' => 'Cat2',
								'page_id' => 2,
								'page_is_redirect' => false,
								'page_is_new' => false,
								'page_latest' => 0,
								'page_touched' => 0,
							],
							WikiAwareEntity::LOCAL
						)
					] );
				} else {
					return new ArrayIterator( [] );
				}
			}
		);

		$pageStore->method( 'newSelectQueryBuilder' )
			->willReturn( $pageSelectQueryBuilder );

		$this->setService( 'PageStore', $pageStore );
	}

	public function testParsedValue() {
		$feature = new InCategoryFeature( new HashSearchConfig( [], [ HashSearchConfig::FLAG_INHERIT ] ) );
		$this->assertParsedValue( $feature, 'incategory:test',
			[ 'names' => [ 'test' ], 'pageIds' => [] ] );
		$this->assertParsedValue( $feature, 'incategory:foo|bar',
			[ 'names' => [ 'foo', 'bar' ], 'pageIds' => [] ] );
		$this->assertParsedValue( $feature, 'incategory:id:123',
			[ 'names' => [], 'pageIds' => [ '123' ] ] );
		$this->assertParsedValue( $feature, 'incategory:id:123|id:321',
			[ 'names' => [], 'pageIds' => [ '123', '321' ] ] );
	}

	public function testExpandedData() {
		$this->mockDB();
		$feature = new InCategoryFeature( new HashSearchConfig( [], [ HashSearchConfig::FLAG_INHERIT ] ) );
		$this->assertExpandedData( $feature, "incategory:test|id:2",
			[ 'test', 'Cat2' ] );
	}

	public function testTooManyCategoriesWarning() {
		$this->assertParsedValue(
			new InCategoryFeature( new \HashConfig( [
				'CirrusSearchMaxIncategoryOptions' => 2,
			] ) ),
			'incategory:a|b|c',
			[ 'names' => [ 'a', 'b' ], 'pageIds' => [] ],
			[ [ 'cirrussearch-feature-too-many-conditions', 'incategory', 2 ] ]
		);
	}

	public function testCategoriesMustExistWarning() {
		$this->assertExpandedData(
			new InCategoryFeature( new \HashConfig( [
				'CirrusSearchMaxIncategoryOptions' => 2,
			] ) ),
			'incategory:id:23892835|id:23892834',
			[],
			[ [ 'cirrussearch-incategory-feature-no-valid-categories', 'incategory' ] ]
		);
	}
}
