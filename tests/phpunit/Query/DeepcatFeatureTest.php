<?php

namespace CirrusSearch\Query;

use MediaWiki\Sparql\SparqlClient;
use MediaWiki\Sparql\SparqlException;
use Title;

/**
 * @group CirrusSearch
 * @covers \CirrusSearch\Query\DeepcatFeature
 */
class DeepcatFeatureTest extends BaseSimpleKeywordFeatureTest {

	private function getSparqlClient( array $expectInQuery, array $result ) {
		$client = $this->getMockBuilder( SparqlClient::class )
			->disableOriginalConstructor()->getMock();
		$client->expects( $this->atMost( 1 ) )->method( 'query' )->willReturnCallback(
			function ( $sparql ) use ( $expectInQuery, $result ) {
				foreach ( $expectInQuery as $expect ) {
					$this->assertContains( $expect, $sparql );
				}
				foreach ( $result as &$row ) {
					$row['out'] = $this->categoryToUrl( $row['out'] );
				}
				return $result;
			}
		);

		return $client;
	}

	public function provideQueries() {
		return [
			'two results' => [
				'Duck',
				[
					[ 'out' => 'Ducks' ],
					[ 'out' => 'Wigeons' ],
				],
				[
					'bool' => [
						'should' => [
							[
								'match' => [
									'category.lowercase_keyword' => [ 'query' => 'Ducks' ]
								]
							],
							[
								'match' => [
									'category.lowercase_keyword' => [ 'query' => 'Wigeons' ]
								]
							],
						]
					]
				]
			],
			"one result" => [
				'"Duck & <duckling>"',
				[
					[ 'out' => 'Wigeons' ],
				],
				[
					'bool' => [
						'should' => [
							[
								'match' => [
									'category.lowercase_keyword' => [ 'query' => 'Wigeons' ]
								]
							],
						]
					]
				]
			],
			"no result" => [
				'Ducks',
				[],
				null
			],
			'too many results' => [
				'Duck',
				[
					[ 'out' => 'Ducks' ],
					[ 'out' => 'Wigeons' ],
					[ 'out' => 'More ducks' ],
					[ 'out' => 'There is no such thing as too many ducks' ],
				],
				null
			],
		];
	}

	/**
	 * Get category full URL
	 * @param string $cat
	 * @return string
	 */
	private function categoryToUrl( $cat ) {
		$title = Title::makeTitle( NS_CATEGORY, $cat );
		return $title->getFullURL( '', false, PROTO_CANONICAL );
	}

	/**
	 * @dataProvider provideQueries
	 * @param string $term
	 * @param array $result
	 * @param array $filters
	 */
	public function testFilter( $term, $result, $filters ) {
		$config = new \HashConfig( [
			'CirrusSearchCategoryDepth' => '3',
			'CirrusSearchCategoryMax' => 3,
			'CirrusSearchCategoryEndpoint' => 'http://acme.test/sparql'
		] );

		$client = $this->getSparqlClient( [
			'bd:serviceParam mediawiki:start <' . $this->categoryToUrl( trim( $term, '"' ) ) . '>',
			'bd:serviceParam mediawiki:depth 3 ',
			'LIMIT 4'
		], $result );
		$feature = new DeepcatFeature( $config, $client );

		$context = $this->mockContextExpectingAddFilter( $filters );
		$feature->apply( $context, "deepcat:$term" );
	}

	public function testTooManyCats() {
		$config = new \HashConfig( [
			'CirrusSearchCategoryDepth' => '3',
			'CirrusSearchCategoryMax' => 3,
			'CirrusSearchCategoryEndpoint' => 'http://acme.test/sparql'
		] );

		$client = $this->getSparqlClient( [
			'bd:serviceParam mediawiki:start <' . $this->categoryToUrl( 'Ducks' ) . '>',
			'bd:serviceParam mediawiki:depth 3 ',
			'LIMIT 4'
		], 	[
				[ 'out' => 'Ducks' ],
				[ 'out' => 'Wigeons' ],
				[ 'out' => 'More ducks' ],
				[ 'out' => 'There is no such thing as too many ducks' ],
			]
		);
		$feature = new DeepcatFeature( $config, $client );

		$context = $this->mockContextExpectingAddFilter( null );
		$context->expects( $this->atLeastOnce() )->method( 'setResultsPossible' )->with( false );
		$feature->apply( $context, "deepcat:Ducks" );
	}

	/**
	 * @dataProvider provideQueries
	 * @param $term
	 * @param $result
	 * @param $filters
	 */
	public function testFilterNoEndpoint( $term, $result, $filters ) {
		$config = new \HashConfig( [
			'CirrusSearchCategoryDepth' => '3',
			'CirrusSearchCategoryMax' => 100,
			'CirrusSearchCategoryEndpoint' => null
		] );

		$client = $this->getSparqlClient( [], $result );
		$feature = new DeepcatFeature( $config, $client );

		$context = $this->mockContextExpectingAddFilter( null );
		$feature->apply( $context, "deepcat:$term" );
		$this->assertWarnings( $feature, [ [ 'cirrussearch-feature-deepcat-endpoint' ] ],
			"deepcat:$term" );
	}

	public function testSparqlError() {
		$config = new \HashConfig( [
			'CirrusSearchCategoryDepth' => '3',
			'CirrusSearchCategoryMax' => 100,
			'CirrusSearchCategoryEndpoint' => 'http://acme.test/sparql'
		] );
		$client = $this->getMockBuilder( SparqlClient::class )
			->disableOriginalConstructor()->getMock();
		$client->expects( $this->exactly( 2 ) )->method( 'query' )->willReturnCallback(
			function () {
				throw new SparqlException( "Bad SPARQL error!" );
			}
		);
		$feature = new DeepcatFeature( $config, $client );
		$context = $this->mockContext();
		$feature->apply( $context, "deepcat:Test" );
		$this->assertWarnings( $feature, [ [ 'cirrussearch-feature-deepcat-exception' ] ],
			"deepcat:Test" );
	}

}
