<?php

namespace CirrusSearch\Query;

use ArrayIterator;
use CirrusSearch\CirrusIntegrationTestCase;
use CirrusSearch\CrossSearchStrategy;
use CirrusSearch\HashSearchConfig;
use MediaWiki\Config\HashConfig;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Page\PageSelectQueryBuilder;
use MediaWiki\Page\PageStore;
use MediaWiki\Page\PageStoreRecord;
use Wikimedia\Rdbms\IReadableDatabase;

/**
 * @covers \CirrusSearch\Query\InCategoryFeature
 * @group CirrusSearch
 * @group Database
 * @todo Remove this test from the Database group when DI becomes possible for the Category class.
 */
class InCategoryFeatureTest extends CirrusIntegrationTestCase {
	use SimpleKeywordFeatureTestTrait;

	public static function parseProvider() {
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
		$feature = new InCategoryFeature( new HashConfig( [
			'CirrusSearchMaxIncategoryOptions' => 2,
		] ), $this->mockPageStore() );
		$this->assertFilter( $feature, $term, $expected, $warnings );
		if ( $expected === null ) {
			$this->assertNoResultsPossible( $feature, $term );
		}
	}

	public function testCrossSearchStrategy() {
		$feature = new InCategoryFeature( new HashSearchConfig( [] ), $this->mockPageStore() );

		$this->assertCrossSearchStrategy( $feature, "incategory:foo", CrossSearchStrategy::allWikisStrategy() );
		$this->assertCrossSearchStrategy( $feature, "incategory:foo|bar", CrossSearchStrategy::allWikisStrategy() );
		$this->assertCrossSearchStrategy( $feature, "incategory:id:123", CrossSearchStrategy::hostWikiOnlyStrategy() );
		$this->assertCrossSearchStrategy( $feature, "incategory:foo|id:123", CrossSearchStrategy::hostWikiOnlyStrategy() );
	}

	/**
	 * Injects a PageStore that knows about a fake page with id of 2
	 * for use in test cases.
	 *
	 * @return PageStore
	 */
	private function mockPageStore(): PageStore {
		$pageStore = $this->createPartialMock( PageStore::class, [ 'newSelectQueryBuilder' ] );
		$pageSelectQueryBuilder = $this->getMockBuilder( PageSelectQueryBuilder::class )
			->setConstructorArgs(
				[
					$this->createMock( IReadableDatabase::class ),
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
		return $pageStore;
	}

	public function testParsedValue() {
		$feature = new InCategoryFeature( new HashSearchConfig( [], [ HashSearchConfig::FLAG_INHERIT ] ), $this->mockPageStore() );
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
		$feature = new InCategoryFeature( new HashSearchConfig( [], [ HashSearchConfig::FLAG_INHERIT ] ), $this->mockPageStore() );
		$this->assertExpandedData( $feature, "incategory:test|id:2",
			[ 'test', 'Cat2' ] );
	}

	public function testTooManyCategoriesWarning() {
		$this->assertParsedValue(
			new InCategoryFeature( new HashConfig( [
				'CirrusSearchMaxIncategoryOptions' => 2,
			] ), $this->mockPageStore() ),
			'incategory:a|b|c',
			[ 'names' => [ 'a', 'b' ], 'pageIds' => [] ],
			[ [ 'cirrussearch-feature-too-many-conditions', 'incategory', 2 ] ]
		);
	}

	public function testCategoriesMustExistWarning() {
		$this->assertExpandedData(
			new InCategoryFeature( new HashConfig( [
				'CirrusSearchMaxIncategoryOptions' => 2,
			] ), $this->mockPageStore() ),
			'incategory:id:23892835|id:23892834',
			[],
			[ [ 'cirrussearch-incategory-feature-no-valid-categories', 'incategory' ] ]
		);
	}
}
