<?php

namespace CirrusSearch\Query;

use CirrusSearch\CrossSearchStrategy;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Search\SearchContext;

/**
 * @covers \CirrusSearch\Query\PrefixFeature
 * @covers \CirrusSearch\Query\SimpleKeywordFeature
 * @group CirrusSearch
 */
class PrefixFeatureTest extends BaseSimpleKeywordFeatureTest {
	public function parseProvider() {
		return [
			'simple' => [
				'prefix:test',
				'test',
				NS_MAIN,
				'',
			],
			'simple quoted' => [
				'prefix:"foo bar"',
				"foo bar",
				NS_MAIN,
				'',
			],
			'simple quoted empty will only set the NS_MAIN filter' => [
				'prefix:""',
				null,
				NS_MAIN,
				'',
			],
			'simple namespaced' => [
				'prefix:help:test',
				'test',
				NS_HELP,
				'',
			],
			'simple quoted & namespaced can trim quotes' => [
				'prefix:help:"foo bar"',
				'foo bar',
				NS_HELP,
				'',
			],
			'simple all quoted & namespaced can trim quotes' => [
				'prefix:"help:foo bar"',
				'foo bar',
				NS_HELP,
				'',
			],
			'simple quoted empty & namespaced is not completely ignored' => [
				'prefix:help:""',
				null,
				NS_HELP,
				'',
			],
			'combined' => [
				'foo prefix:test',
				'test',
				NS_MAIN,
				// trailing space explicitly added by SimpleKeywordFeature
				'foo ',
			],
			'combined quoted' => [
				'baz prefix:"foo bar"',
				"foo bar",
				NS_MAIN,
				'baz ',
			],
			'combined quoted empty only sets NS_MAIN' => [
				'foo prefix:""',
				null,
				NS_MAIN,
				'foo ',
			],
			'combined namespaced' => [
				'foo prefix:help:test',
				'test',
				NS_HELP,
				'foo ',
			],
			'combined quoted & namespaced can trim the title' => [
				'foo prefix:help:"test"',
				'test',
				NS_HELP,
				'foo ',
			],
			'combined all quoted & namespaced can trim the title' => [
				'foo prefix:"help:test"',
				'test',
				NS_HELP,
				'foo ',
			],
			'combined quoted empty & namespaced' => [
				'foo prefix:help:""',
				null,
				NS_HELP,
				'foo ',
			],
			'prefix is greedy' => [
				'foo prefix:foo bar',
				'foo bar',
				NS_MAIN,
				'foo ',
			],
			'prefix does not need to convert _ to space since it is handled by elastic' => [
				'foo prefix:foo_bar',
				'foo_bar',
				NS_MAIN,
				'foo ',
			],
			'prefix can also be used as a simple namespace filter' => [
				'foo prefix:help:',
				null,
				NS_HELP,
				'foo ',
			],
			'prefix does not trim quotes if the query is ambiguous regarding greedy behaviors' => [
				'foo prefix:"foo bar" test',
				'"foo bar" test',
				NS_MAIN,
				'foo ',
			],
			'prefix does not ignore negation' => [
				'foo -prefix:"foo bar"',
				'foo bar',
				NS_MAIN,
				'foo ',
			]
		];
	}

	/**
	 * @dataProvider parseProvider
	 */
	public function testParse( $query, $filterValue, $namespace, $expectedRemaining ) {
		$prefixQuery = null;
		if ( $filterValue !== null ) {
			$prefixQuery = new \Elastica\Query\Match();
			$prefixQuery->setFieldQuery( 'title.prefix', $filterValue );
		}

		$feature = new PrefixFeature();
		if ( $prefixQuery !== null ) {
			$this->assertCrossSearchStrategy( $feature, $query, CrossSearchStrategy::hostWikiOnlyStrategy() );
		}
		$this->assertParsedValue( $feature, $query, null, [] );
		$this->assertExpandedData( $feature, $query, [], [] );
		$this->assertFilter( $feature, $query, $prefixQuery, [] );
		$this->assertRemaining( $feature, $query, $expectedRemaining );

		$originalNs = [ NS_FILE_TALK ];
		$context = new SearchContext( new HashSearchConfig( [] ), $originalNs );
		$feature->apply( $context, $query );
		if ( $namespace !== null ) {
			$this->assertArrayEquals( $context->getNamespaces(), [ $namespace ] );
		} else {
			$this->assertArrayEquals( $context->getNamespaces(), $originalNs );
		}
	}

	public function testEmpty() {
		$this->assertNotConsumed( new PrefixFeature(), 'foo bar' );
	}
}
