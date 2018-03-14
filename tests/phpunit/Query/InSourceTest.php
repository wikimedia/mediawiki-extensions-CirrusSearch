<?php

namespace CirrusSearch\Query;

use CirrusSearch\Extra\Query\SourceRegex;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Search\Filters;

/**
 * @covers \CirrusSearch\Query\BaseRegexFeature
 * @covers \CirrusSearch\Query\SimpleKeywordFeature
 * @covers \CirrusSearch\Query\InSourceFeature
 * @group CirrusSearch
 */
class InSourceTest extends BaseSimpleKeywordFeatureTest {

	/**
	 * @dataProvider provideSimpleQueries
	 * @param $query
	 * @param $expectedRemaining
	 * @param $negated
	 * @param $filterValue
	 */
	public function testSimple( $query, $expectedRemaining, $negated, $filterValue ) {
		$context = $this->mockContext();
		if ( $filterValue !== null ) {
			$qsQuery = Filters::insource( $context->escaper(), $context, $filterValue );
			$context->expects( $this->once() )
				->method( $negated ? 'addNotFilter' : 'addFilter' )
				->with( $qsQuery );
			$context->expects( $this->never() )
				->method( $negated ? 'addFilter' : 'addNotFilter' );
			if ( !$negated ) {
				$context->expects( $this->once() )
					->method( 'addHighlightField' )
					->with( 'source_text', [ 'query' => $qsQuery ] );
			}
		} else {
			$context->expects( $this->never() )
				->method( 'addFilter' );
			$context->expects( $this->never() )
				->method( 'addNotFilter' );
		}

		$feature = new InSourceFeature( new HashSearchConfig( [] ) );
		$remaining = $feature->apply( $context, $query );
		$this->assertEquals( $expectedRemaining, $remaining );
	}

	public static function provideSimpleQueries() {
		return [
			'doesnt absord unrelated things' => [
				'foo bar',
				'foo bar',
				false,
				null,
			],
			'supports unquoted value' => [
				'insource:bar',
				'',
				false,
				'bar',
			],
			'stop on first quote' => [
				'insource:bar"bar"',
				'"bar"',
				false,
				'bar',
			],
			'FIXME: but does not support escaping quotes' => [
				'insource:bar\"bar',
				'"bar',
				false,
				'bar\\',
			],
			'doesnt stop on /' => [
				'insource:bar/bar',
				'',
				false,
				'bar/bar',
			],
			'supports negation' => [
				'-insource:bar',
				'',
				true,
				'bar',
			],
			'can be combined' => [
				'foo insource:bar baz',
				'foo baz',
				false,
				'bar',
			],
			'can be quoted (emits a querystring phrase query)' => [
				'insource:"foo bar"',
				'',
				false,
				'"foo bar"',
			],
			'can be quoted with escaped quotes (remains escaped in the query)' => [
				'insource:"foo\"bar"',
				'',
				false,
				'"foo\"bar"',
			],
			'is not confused by regex suffix' => [
				'insource:"foo bar"i',
				'i',
				false,
				'"foo bar"',
			],
			'can be quoted and combined  (emits a querystring phrase query)' => [
				'boo insource:"foo bar" baz',
				'boo baz',
				false,
				'"foo bar"',
			],
			'gracefully handles text including ~' => [
				'insource:this~that',
				'',
				false,
				'this~that',
			],
			'do not espcape fuzzy char' => [
				'insource:fuzzy~2',
				'',
				false,
				'fuzzy~2',
			],
			'do not escape wildcard char' => [
				'insource:fuzzy*',
				'',
				false,
				'fuzzy*',
			],
		];
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
				->with( $this->callback( function ( SourceRegex $x ) use ( $filterValue, $insensitive ) {
					return $filterValue === $x->getParam( 'regex' ) &&
						$x->getParam( 'field' ) === 'source_text' &&
						$x->getParam( 'ngram_field' ) === 'source_text.trigram' &&
						!$insensitive === $x->getParam( 'case_sensitive' );
				} ) );
			$context->expects( $this->never() )
				->method( $negated ? 'addFilter' : 'addNotFilter' );
			if ( !$negated ) {
				$context->expects( $this->once() )
					->method( 'addHighlightField' )
					->with( 'source_text',
						[
							'pattern' => $filterValue,
							'locale' => 'en',
							'insensitive' => $insensitive
						] );
			}
		} else {
			$context->expects( $this->never() )
				->method( 'addFilter' );
			$context->expects( $this->never() )
				->method( 'addNotFilter' );
		}
		$feature = new InSourceFeature( new HashSearchConfig(
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
				'insource:/bar/',
				'',
				false,
				'bar',
				false,
			],
			'supports simple case insensitive regex' => [
				'insource:/bar/i',
				'',
				false,
				'bar',
				true,
			],
			'supports negation' => [
				'-insource:/bar/',
				'',
				true,
				'bar',
				false,
			],
			'supports negation simple case insensitive regex' => [
				'-insource:/bar/i',
				'',
				true,
				'bar',
				true,
			],
			'do not unescape the regex' => [
				'insource:/foo\/bar/',
				'',
				false,
				'foo\/bar',
				false,
			],
			'do not unescape the regex and keep insensitive flag' => [
				'insource:/foo\/bar/i',
				'',
				false,
				'foo\/bar',
				true,
			],
			'do not stop on quote' => [
				'insource:/foo"bar/i',
				'',
				false,
				'foo"bar',
				true,
			],
		];
	}
}
