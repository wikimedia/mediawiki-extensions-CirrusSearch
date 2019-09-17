<?php

namespace CirrusSearch\Fallbacks;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\Search\BaseCirrusSearchResultSet;
use CirrusSearch\Search\CirrusSearchResultSet;
use CirrusSearch\Search\SearchQuery;
use CirrusSearch\Search\TitleHelper;
use CirrusSearch\Searcher;
use CirrusSearch\Test\DummySearchResultSet;
use Elastica\Query;
use Elastica\Response;
use Elastica\Result;
use Elastica\ResultSet;
use Elastica\ResultSet\DefaultBuilder;

class BaseFallbackMethodTest extends CirrusTestCase {

	public function getSearcherFactoryMock( SearchQuery $query = null, CirrusSearchResultSet $resultSet = null ) {
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
		$resultSet = DummySearchResultSet::fakeTotalHits( $this->newTitleHelper(), $mainTotal, $interwikiTotals );
		$mock = $this->getMockForTrait( FallbackMethodTrait::class );
		$this->assertEquals( $met, $mock->resultsThreshold( $resultSet, $threshold ) );
		if ( $threshold === 1 ) {
			// Test default method param
			$this->assertEquals( $mock->resultsThreshold( $resultSet ),
				$mock->resultsThreshold( $resultSet, $threshold ) );
		}
	}

	/**
	 * @covers \CirrusSearch\Fallbacks\FallbackMethodTrait::resultContainsFullyHighlightedMatch()
	 */
	public function testResultContainsFullyHighlightedMatch() {
		$mock = $this->getMockForTrait( FallbackMethodTrait::class );

		$resultset = new \Elastica\ResultSet( new Response( [] ), new Query(), [] );
		$this->assertFalse( $mock->resultContainsFullyHighlightedMatch( $resultset ) );

		$resultset = new \Elastica\ResultSet( new Response( [] ), new Query(), [
			new Result( [] )
		] );
		$this->assertFalse( $mock->resultContainsFullyHighlightedMatch( $resultset ) );

		$resultset = new \Elastica\ResultSet( new Response( [] ), new Query(), [
			new Result( [
				'highlight' => [
					'title' => 'foo' . Searcher::HIGHLIGHT_PRE_MARKER . 'bar' . Searcher::HIGHLIGHT_POST_MARKER
				]
			] )
		] );
		$this->assertFalse( $mock->resultContainsFullyHighlightedMatch( $resultset ) );

		$resultset = new \Elastica\ResultSet( new Response( [] ), new Query(), [
			new Result( [
				'highlight' => [
					'title' => Searcher::HIGHLIGHT_PRE_MARKER . 'foo bar' . Searcher::HIGHLIGHT_POST_MARKER
				]
			] )
		] );
		$this->assertFalse( $mock->resultContainsFullyHighlightedMatch( $resultset ) );
	}

	/**
	 * @param array $response
	 * @param bool $containedSyntax
	 * @param TitleHelper|null $titleHelper
	 * @return CirrusSearchResultSet
	 */
	protected function newResultSet(
		array $response,
		$containedSyntax = false,
		TitleHelper $titleHelper = null
	): CirrusSearchResultSet {
		$titleHelper = $titleHelper ?: $this->newTitleHelper();
		$resultSet = ( new DefaultBuilder() )->buildResultSet( new Response( $response ), new Query() );
		return new class( $resultSet, $titleHelper, $containedSyntax ) extends BaseCirrusSearchResultSet {
			/** @var ResultSet */
			private $resultSet;
			/** @var TitleHelper */
			private $titleHelper;
			/** @var bool */
			private $containedSyntax;

			public function __construct( ResultSet $resultSet, TitleHelper $titleHelper, $containedSyntax ) {
				$this->resultSet = $resultSet;
				$this->titleHelper = $titleHelper;
				$this->containedSyntax = $containedSyntax;
			}

			/**
			 * @inheritDoc
			 */
			protected function transformOneResult( \Elastica\Result $result ) {
				return new \CirrusSearch\Search\Result( $result );
			}

			/**
			 * @inheritDoc
			 */
			public function getElasticaResultSet() {
				return $this->resultSet;
			}

			/**
			 * @return bool
			 */
			public function searchContainedSyntax() {
				return $this->containedSyntax;
			}

			protected function getTitleHelper(): TitleHelper {
				return $this->titleHelper;
			}
		};
	}
}
