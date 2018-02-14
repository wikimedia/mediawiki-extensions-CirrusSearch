<?php


namespace CirrusSearch\Query;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Search\SearchContext;

/**
 * @covers \CirrusSearch\Query\PrefixFeature
 * @group CirrusSearch
 */
class PrefixFeatureTest extends CirrusTestCase {
	public function parseProvider() {
		return [
			'doesnt absord unrelated things' => [
				'foo bar',
				null,
				null,
				'foo bar'
			],
			'simple' => [
				'prefix:test',
				'test',
				NS_MAIN,
				''
			],
			'simple quoted' => [
				'prefix:"foo bar"',
				"foo bar",
				NS_MAIN,
				''
			],
			'FIXME: simple quoted empty is completely ignored' => [
				'prefix:""',
				null,
				null,
				'prefix:""'
			],
			'simple namespaced' => [
				'prefix:help:test',
				'test',
				NS_HELP,
				''
			],
			'FIXME: simple quoted & namespaced partially trims quotes' => [
				'prefix:help:"foo bar"',
				'"foo bar',
				NS_HELP,
				''
			],
			'simple quoted empty & namespaced is not completely ignored' => [
				'prefix:help:""',
				null,
				NS_HELP,
				''
			],
			'combined' => [
				'foo prefix:test',
				'test',
				NS_MAIN,
				'foo'
			],
			'combined quoted' => [
				'baz prefix:"foo bar"',
				"foo bar",
				NS_MAIN,
				'baz',
			],
			'FIXME: combined quoted empty is completly ignored' => [
				'foo prefix:""',
				null,
				null,
				'foo prefix:""'
			],
			'combined namespaced' => [
				'foo prefix:help:test',
				'test',
				NS_HELP,
				'foo'
			],
			'FIXME: combined quoted & namespaced handle quotes in a weird way' => [
				'foo prefix:help:"test"',
				'"test',
				NS_HELP,
				'foo'
			],
			'combined quoted empty & namespaced' => [
				'foo prefix:help:""',
				null,
				NS_HELP,
				'foo'
			],
			'prefix is greedy' => [
				'foo prefix:foo bar',
				'foo bar',
				NS_MAIN,
				'foo'
			],
			'prefix converts _ to space' => [
				'foo prefix:foo_bar',
				'foo bar',
				NS_MAIN,
				'foo'
			],
			'prefix can also be used as a simple namespace filter' => [
				'foo prefix:help:',
				null,
				NS_HELP,
				'foo'
			],
			'FIXME: prefix does handle quotes in a weird way' => [
				'foo prefix:"foo bar" test',
				'foo bar" test',
				NS_MAIN,
				'foo'
			],
			'FIXME: sadly prefix ignores negation' => [
				'foo -prefix:"foo bar"',
				'foo bar',
				NS_MAIN,
				'foo '
			]

		];
	}

	/**
	 * @dataProvider parseProvider
	 */
	public function testParse( $query, $filterValue, $namespace, $expectedRemaining ) {
		$context = $this->getMockBuilder( SearchContext::class )
			->disableOriginalConstructor()
			->getMock();
		if ( $filterValue !== null ) {
			$prefixQuery = new \Elastica\Query\Match();
			$prefixQuery->setFieldQuery( 'title.prefix', $filterValue );
			$context->expects( $this->once() )
				->method( 'addFilter' )
				->with( $prefixQuery );
		} else {
			$context->expects( $this->never() )
				->method( 'addFilter' );
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
