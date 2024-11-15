<?php

namespace CirrusSearch\Parser;

use CirrusSearch\CirrusTestCase;

/**
 * @covers \CirrusSearch\Parser\BasicQueryClassifier
 * @group CirrusSearch
 */
class BasicQueryClassifierTest extends CirrusTestCase {

	public static function provideQueries() {
		yield 'simple' => [ 'foo', [ BasicQueryClassifier::SIMPLE_BAG_OF_WORDS ] ];
		yield 'simple unquoted phrase' => [ 'foo bar', [ BasicQueryClassifier::SIMPLE_BAG_OF_WORDS ] ];
		yield 'empty' => [ '', [] ];
		yield 'simple phrase' => [ '"hello world"', [ BasicQueryClassifier::SIMPLE_PHRASE ] ];
		yield 'simple unbalanced phrase' => [ 'hello "world', [ BasicQueryClassifier::BOGUS_QUERY ] ];
		yield 'words and simple phrase' => [ 'hello "world"', [ BasicQueryClassifier::BAG_OF_WORDS_WITH_PHRASE ] ];
		yield 'wildcard' => [ 'hop*d', [ BasicQueryClassifier::COMPLEX_QUERY ] ];
		yield 'prefix' => [ 'hop*', [ BasicQueryClassifier::COMPLEX_QUERY ] ];
		yield 'fuzzy' => [ 'hop~', [ BasicQueryClassifier::COMPLEX_QUERY ] ];
		yield 'phrase prefix' => [ '"foo bar*"', [ BasicQueryClassifier::COMPLEX_QUERY ] ];
		yield 'complex phrase' => [ '"foo bar"~', [ BasicQueryClassifier::COMPLEX_QUERY ] ];
		yield 'complex phrase bis' => [ '"foo bar"~2~', [ BasicQueryClassifier::COMPLEX_QUERY ] ];
		yield 'keyword' => [ 'intitle:foo', [ BasicQueryClassifier::COMPLEX_QUERY ] ];
		yield 'boolean' => [ 'hello AND world', [ BasicQueryClassifier::COMPLEX_QUERY ] ];
		yield 'negation' => [ 'hello -world', [ BasicQueryClassifier::COMPLEX_QUERY ] ];
		yield 'negation explicit' => [ 'hello AND NOT world', [ BasicQueryClassifier::COMPLEX_QUERY ] ];
		yield 'complex' => [
			'intitle:foo AND hello AND NOT world* AND "foo bar"~3~',
			[ BasicQueryClassifier::COMPLEX_QUERY ]
		];
		yield 'complex & bogus' => [
			'intitle:foo AND hello AND NOT -world* AND "foo bar"~3~',
			[ BasicQueryClassifier::BOGUS_QUERY, BasicQueryClassifier::COMPLEX_QUERY ]
		];
		yield 'morelike_only' => [ 'morelike:foo', [ BasicQueryClassifier::COMPLEX_QUERY, BasicQueryClassifier::MORE_LIKE_ONLY ] ];
	}

	/**
	 * @dataProvider provideQueries
	 */
	public function test( $query, $classes ) {
		$parser = $this->createNewFullTextQueryParser( $this->newHashSearchConfig( [] ) );
		$parsedQuery = $parser->parse( $query );
		$classifier = new BasicQueryClassifier();
		sort( $classes );
		$actualClasses = $classifier->classify( $parsedQuery );
		sort( $actualClasses );
		$this->assertEquals( $classes, $actualClasses );
	}

	public function testClasses() {
		$classifier = new BasicQueryClassifier();
		$this->assertEquals( [
				BasicQueryClassifier::SIMPLE_BAG_OF_WORDS,
				BasicQueryClassifier::SIMPLE_PHRASE,
				BasicQueryClassifier::BAG_OF_WORDS_WITH_PHRASE,
				BasicQueryClassifier::COMPLEX_QUERY,
				BasicQueryClassifier::BOGUS_QUERY,
				BasicQueryClassifier::MORE_LIKE_ONLY,
			], $classifier->classes() );
	}
}
