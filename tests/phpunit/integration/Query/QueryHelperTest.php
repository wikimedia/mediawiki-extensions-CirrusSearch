<?php

namespace CirrusSearch\Query;

use CirrusSearch\CirrusIntegrationTestCase;
use Elastica\Query\MatchQuery;

/**
 * @group CirrusSearch
 * @covers \CirrusSearch\Query\QueryHelper
 */
class QueryHelperTest extends CirrusIntegrationTestCase {

	public function provideMatchPage(): array {
		return [
			[ 'Page title', 'foo', 'Page title', false ],
			[ 'Page title', 'foo', 'page_title', false ],
			[ 'Page_title', 'foo', 'Page title', true ],
			[ 'Page_title', 'foo', 'Page_title', true ],
		];
	}

	/**
	 * @dataProvider provideMatchPage
	 *
	 * @param mixed $expected
	 * @param string $field
	 * @param string $title
	 * @param string $underscores
	 */
	public function testMatchPage( $expected, $field, $title, $underscores ) {
		$match = QueryHelper::matchPage( $field, $title, $underscores );

		$this->matchQueryAssertions( $match, $field, $expected );
	}

	public function provideMatchCategory(): array {
		return [
			[ 'Page title', 'foo', 'Page title' ],
			[ 'Page title', 'foo', 'Page_title' ],
		];
	}

	/**
	 * @dataProvider provideMatchCategory
	 *
	 * @param mixed $expected
	 * @param string $field
	 * @param string $title
	 */
	public function testMatchCategory( $expected, $field, $title ) {
		$match = QueryHelper::matchCategory( $field, $title );

		$this->matchQueryAssertions( $match, $field, $expected );
	}

	/**
	 * @param MatchQuery $match
	 * @param string $field
	 * @param string $expected
	 * @return void
	 */
	private function matchQueryAssertions( MatchQuery $match, $field, $expected ): void {
		$this->assertInstanceOf( MatchQuery::class, $match );

		$expectedArray = [ $field => [ 'query' => $expected ] ];

		$this::assertEquals( json_encode( $expectedArray, JSON_PRETTY_PRINT ),
			json_encode( $match->getParams(), JSON_PRETTY_PRINT ) );
	}
}
