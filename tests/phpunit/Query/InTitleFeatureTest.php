<?php

namespace CirrusSearch\Query;

use CirrusSearch\Extra\Query\SourceRegex;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Search\Escaper;
use CirrusSearch\Search\SearchContext;
use Elastica\Query\BoolQuery;

/**
 * @covers \CirrusSearch\Query\InTitleFeature
 * @covers \CirrusSearch\Query\BaseRegexFeature
 * @covers \CirrusSearch\Query\SimpleKeywordFeature
 * @group CirrusSearch
 */
class InTitleFeatureTest extends BaseSimpleKeywordFeatureTest {

	public function parseProvider() {
		$defaults = [
			'fields' => [ 'title', 'redirect.title' ],
			'default_operator' => 'AND',
			'allow_leading_wildcard' => true,
			'fuzzy_prefix_length' => 2,
			'rewrite' => 'top_terms_boost_1024',
		];
		return [
			'basic search' => [
				[ 'query_string' => $defaults + [
					'query' => 'bridge',
				] ],
				'bridge ',
				false,
				'intitle:bridge',
			],
			'fuzzy search' => [
				[ 'query_string' => $defaults + [
					'query' => 'bridge~2',
				] ],
				'bridge~2 ',
				true,
				'intitle:bridge~2',
			],
			'gracefully handles titles including ~' => [
				[ 'query_string' => $defaults + [
					'query' => 'this\~that',
				] ],
				'this~that ',
				false,
				'intitle:this~that',
			],
			'maintains provided quotes' => [
				[ 'query_string' => $defaults + [
					'query' => '"something or other"',
				] ],
				'"something or other" ',
				false,
				'intitle:"something or other"',
			],
			'contains a star' => [
				[ 'query_string' => [
					'query' => 'zomg*',
					'fields' => [ 'title.plain', 'redirect.title.plain' ],
				] + $defaults ],
				'zomg* ',
				false,
				'intitle:zomg*'
			],
		];
	}

	/**
	 * @dataProvider parseProvider
	 */
	public function testParse( array $expectedQuery, $expectedTerm, $isFuzzy, $term ) {
		$context = $this->mockContextExpectingAddFilter( $expectedQuery );
		$context->expects( $this->once() )
			->method( 'setFuzzyQuery' )
			->with( $isFuzzy );

		// This test is kinda-sorta testing the escaper too ... maybe not optimal but simple
		$context->expects( $this->once() )
			->method( 'escaper' )
			->will( $this->returnValue( new Escaper( 'en' ) ) );

		$feature = new InTitleFeature( new HashSearchConfig( [] ) );
		$this->assertEquals(
			$expectedTerm,
			$feature->apply( $context, $term )
		);
	}

	public function testNegatingDoesntKeepTerm() {
		$context = $this->getMockBuilder( SearchContext::class )
			->disableOriginalConstructor()
			->getMock();

		$context->expects( $this->once() )
			->method( 'escaper' )
			->will( $this->returnValue( new Escaper( 'en' ) ) );

		$feature = new InTitleFeature( new HashSearchConfig( [] ) );
		$this->assertEquals( '', $feature->apply( $context, '-intitle:mediawiki' ) );
	}

	/**
	 * @dataProvider provideRegexQueries
	 * @param $query
	 * @param $expectedRemaining
	 * @param $negated
	 * @param $filterValue
	 */
	public function testRegex( $query, $expectedRemaining, $negated, $filterValue, $insensitive ) {
		$context = $this->mockContext();

		if ( $filterValue !== null ) {
			$context->expects( $this->once() )
				->method( $negated ? 'addNotFilter' : 'addFilter' )
				->with( $this->callback( function ( BoolQuery $x ) use ( $filterValue, $insensitive ) {
					$this->assertTrue( $x->hasParam( 'should' ) );
					$this->assertTrue( is_array( $x->getParam( 'should' ) ) );
					$this->assertEquals( 2, count( $x->getParam( 'should' ) ) );
					$regex = $x->getParam( 'should' )[0];
					$this->assertInstanceOf( SourceRegex::class, $regex );
					$this->assertEquals( $filterValue, $regex->getParam( 'regex' ) );
					$this->assertEquals( 'title.trigram', $regex->getParam( 'ngram_field' ) );
					$this->assertEquals( !$insensitive, $regex->getParam( 'case_sensitive' ) );
					$regex = $x->getParam( 'should' )[1];
					$this->assertInstanceOf( SourceRegex::class, $regex );
					$this->assertEquals( $filterValue, $regex->getParam( 'regex' ) );
					$this->assertEquals( 'redirect.title.trigram', $regex->getParam( 'ngram_field' ) );
					$this->assertEquals( !$insensitive, $regex->getParam( 'case_sensitive' ) );
					return true;
				} ) );
			$context->expects( $this->never() )
				->method( $negated ? 'addFilter' : 'addNotFilter' );
			if ( !$negated ) {
				$context->expects( $this->exactly( 2 ) )
					->method( 'addHighlightField' )
					->withConsecutive(
						[
							'title', [
								'pattern' => $filterValue,
								'locale' => 'en',
								'insensitive' => $insensitive
							]
						],
						[
							'redirect.title',
							[
								'pattern' => $filterValue,
								'locale' => 'en',
								'insensitive' => $insensitive
							]
						]
					);
			}
		} else {
			$context->expects( $this->never() )
				->method( 'addFilter' );
			$context->expects( $this->never() )
				->method( 'addNotFilter' );
		}
		$feature = new InTitleFeature( new HashSearchConfig(
			[
				'CirrusSearchEnableRegex' => true,
				'CirrusSearchWikimediaExtraPlugin' => [ 'regex' => [ 'use' => true ] ]
			], [ 'inherit' ] ) );
		$remaining = $feature->apply( $context, $query );
		$this->assertEquals( $expectedRemaining, $remaining );
	}

	public static function provideRegexQueries() {
		return [
			'doesnt absord unrelated things' => [
				'foo bar',
				'foo bar',
				false,
				null,
				false,
			],
			'supports simple regex' => [
				'intitle:/bar/',
				'',
				false,
				'bar',
				false,
			],
			'supports simple case insensitive regex' => [
				'intitle:/bar/i',
				'',
				false,
				'bar',
				true,
			],
			'supports negation' => [
				'-intitle:/bar/',
				'',
				true,
				'bar',
				false,
			],
			'supports negation simple case insensitive regex' => [
				'-intitle:/bar/i',
				'',
				true,
				'bar',
				true,
			],
			'do not unescape the regex' => [
				'intitle:/foo\/bar/',
				'',
				false,
				'foo\\/bar',
				false,
			],
			'do not unescape the regex and keep insensitive flag' => [
				'intitle:/foo\/bar/i',
				'',
				false,
				'foo\\/bar',
				true,
			],
		];
	}
}
