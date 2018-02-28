<?php


namespace CirrusSearch\Query;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Search\SearchContext;

/**
 * @covers \CirrusSearch\Query\PrefixFeature
 * @covers \CirrusSearch\Query\SimpleKeywordFeature
 * @group CirrusSearch
 */
class PrefixFeatureTest extends CirrusTestCase {
	public function parseProvider() {
		return [
			'doesnt absord unrelated things' => [
				'foo bar',
				null,
				null,
				'foo bar',
				false,
			],
			'simple' => [
				'prefix:test',
				'test',
				NS_MAIN,
				'',
				false,
			],
			'simple quoted' => [
				'prefix:"foo bar"',
				"foo bar",
				NS_MAIN,
				'',
				false,
			],
			'simple quoted empty will only set the NS_MAIN filter' => [
				'prefix:""',
				null,
				NS_MAIN,
				'',
				false,
			],
			'simple namespaced' => [
				'prefix:help:test',
				'test',
				NS_HELP,
				'',
				false,
			],
			'simple quoted & namespaced can trim quotes' => [
				'prefix:help:"foo bar"',
				'foo bar',
				NS_HELP,
				'',
				false,
			],
			'simple all quoted & namespaced can trim quotes' => [
				'prefix:"help:foo bar"',
				'foo bar',
				NS_HELP,
				'',
				false,
			],
			'simple quoted empty & namespaced is not completely ignored' => [
				'prefix:help:""',
				null,
				NS_HELP,
				'',
				false,
			],
			'combined' => [
				'foo prefix:test',
				'test',
				NS_MAIN,
				// trailing space explicitly added by SimpleKeywordFeature
				'foo ',
				false,
			],
			'combined quoted' => [
				'baz prefix:"foo bar"',
				"foo bar",
				NS_MAIN,
				'baz ',
				false,
			],
			'combined quoted empty only sets NS_MAIN' => [
				'foo prefix:""',
				null,
				NS_MAIN,
				'foo ',
				false,
			],
			'combined namespaced' => [
				'foo prefix:help:test',
				'test',
				NS_HELP,
				'foo ',
				false,
			],
			'combined quoted & namespaced can trim the title' => [
				'foo prefix:help:"test"',
				'test',
				NS_HELP,
				'foo ',
				false,
			],
			'combined all quoted & namespaced can trim the title' => [
				'foo prefix:"help:test"',
				'test',
				NS_HELP,
				'foo ',
				false,
			],
			'combined quoted empty & namespaced' => [
				'foo prefix:help:""',
				null,
				NS_HELP,
				'foo ',
				false,
			],
			'prefix is greedy' => [
				'foo prefix:foo bar',
				'foo bar',
				NS_MAIN,
				'foo ',
				false,
			],
			'prefix does not need to convert _ to space since it is handled by elastic' => [
				'foo prefix:foo_bar',
				'foo_bar',
				NS_MAIN,
				'foo ',
				false,
			],
			'prefix can also be used as a simple namespace filter' => [
				'foo prefix:help:',
				null,
				NS_HELP,
				'foo ',
				false,
			],
			'prefix does not trim quotes if the query is ambiguous regarding greedy behaviors' => [
				'foo prefix:"foo bar" test',
				'"foo bar" test',
				NS_MAIN,
				'foo ',
				false,
			],
			'prefix does not ignore negation' => [
				'foo -prefix:"foo bar"',
				'foo bar',
				NS_MAIN,
				'foo ',
				true,
			]

		];
	}

	/**
	 * @dataProvider parseProvider
	 */
	public function testParse( $query, $filterValue, $namespace, $expectedRemaining, $negated ) {
		$context = $this->getMockBuilder( SearchContext::class )
			->disableOriginalConstructor()
			->getMock();
		if ( $filterValue !== null ) {
			$prefixQuery = new \Elastica\Query\Match();
			$prefixQuery->setFieldQuery( 'title.prefix', $filterValue );
			$context->expects( $this->once() )
				->method( $negated ? 'addNotFilter' : 'addFilter' )
				->with( $prefixQuery );
			$context->expects( $this->never() )
				->method( $negated ? 'addFilter' : 'addNotFilter' );
		} else {
			$context->expects( $this->never() )
				->method( 'addFilter' );
			$context->expects( $this->never() )
				->method( 'addNotFilter' );
		}

		if ( $namespace !== null ) {
			$context->expects( $this->once() )
				->method( 'setNamespaces' )
				->with( [ $namespace ] );
		} else {
			$context->expects( $this->never() )
				->method( 'setNamespaces' );
		}

		$feature = new PrefixFeature( new HashSearchConfig( [] ) );
		$remaining = $feature->apply( $context, $query );
		$this->assertEquals( $expectedRemaining, $remaining );
	}
}
