<?php

namespace CirrusSearch\Query;

use CirrusSearch\CrossSearchStrategy;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Search\SearchContext;
use Elastica\Query\AbstractQuery;
use Elastica\Query\BoolQuery;
use Elastica\Query\Match;
use Elastica\Query\Term;

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
			'prefix can also be used to open on all namespaces' => [
				'foo prefix:all:',
				null,
				null, // null is all
				'foo ',
			],
			'prefix does not misinterpret a trailing :' => [
				'foo prefix:Help:Wikipedia:',
				'Wikipedia:',
				NS_HELP, // null is everything
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
		$assertions = null;

		$assertFilter = function ( AbstractQuery $filter ) use ( $filterValue ) {
			$this->assertInstanceOf( Match::class, $filter );
			$this->assertArrayEquals( [ 'query' => $filterValue ], $filter->getParam( 'title.prefix' ) );
			return true;
		};

		$assertNsFilter = function ( AbstractQuery $filter ) use ( $namespace ) {
			$this->assertInstanceOf( Term::class, $filter );
			$this->assertEquals( $namespace, $filter->getParam( 'namespace' ) );
			return true;
		};

		if ( $filterValue !== null && $namespace !== null ) {
			$assertions = function ( AbstractQuery $filter ) use (
				$filterValue,
				$namespace,
				$assertFilter,
				$assertNsFilter
			) {
				$this->assertInstanceOf( BoolQuery::class, $filter );
				$boolQuery = $filter;
				$queries = $boolQuery->getParam( 'must' );
				$this->assertCount( 2, $queries );
				$valueFilter = $queries[0];
				$nsFilter = $queries[1];
				$assertFilter( $valueFilter );
				$assertNsFilter( $nsFilter );
				return true;
			};
		} elseif ( $filterValue !== null ) {
			$assertions = $assertFilter;
		} elseif ( $namespace !== null ) {
			$assertions = $assertNsFilter;
		}

		$feature = new PrefixFeature();
		if ( $assertions !== null ) {
			$this->assertCrossSearchStrategy( $feature, $query, CrossSearchStrategy::hostWikiOnlyStrategy() );
		}
		$parsedValue = [ 'value' => $filterValue ];
		if ( $namespace !== null ) {
			$parsedValue['namespace'] = $namespace;
		}
		$this->assertParsedValue( $feature, $query, $parsedValue, [] );
		$this->assertExpandedData( $feature, $query, [], [] );
		$this->assertFilter( $feature, $query, $assertions, [] );
		$this->assertRemaining( $feature, $query, $expectedRemaining );

		$context = new SearchContext( new HashSearchConfig( [] ),
			$namespace !== null ? [ $namespace ] : null );
		$feature->apply( $context, $query );
		$this->assertEmpty( $context->getWarnings() );
	}

	public function testEmpty() {
		$this->assertNotConsumed( new PrefixFeature(), 'foo bar' );
	}

	public function provideBadPrefixQueries() {
		return [
			'prefix wants all but context is NS_MAIN' => [
				'prefix:all:',
				[ NS_MAIN ],
				true,
			],
			'prefix wants Help but context is NS_MAIN' => [
				'prefix:Help:Test',
				[ NS_MAIN, NS_TALK ],
				true,
			],
			'prefix wants main but context is Help' => [
				'prefix:Test',
				[ NS_HELP ],
				true,
			],
			'prefix wants NS_MAIN and context has it' => [
				'prefix:Test',
				[ NS_MAIN, NS_HELP ],
				false,
			],
			'prefix wants all and context is all' => [
				'prefix:all:',
				[],
				false,
			],
			'prefix wants all and context is null' => [
				'prefix:all:',
				null, // means all
				false,
			],
		];
	}
	/**
	 * @dataProvider provideBadPrefixQueries()
	 */
	public function testDeprecationWarning( $query, $namespace, $hasWarning ) {
		$this->markTestSkipped( "Not activated yet" );
		$context = new SearchContext( new HashSearchConfig( [] ), $namespace );
		$feature = new PrefixFeature();
		$feature->apply( $context, $query );
		$expectedWarnings = [];
		if ( $hasWarning ) {
			$expectedWarnings[] = [ 'cirrussearch-keyword-prefix-ns-mismatch' ];
		}
		$this->assertArrayEquals( $expectedWarnings, $context->getWarnings() );
	}
}
