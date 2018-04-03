<?php


namespace CirrusSearch\Parser\QueryStringRegex;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\Parser\AST\ParsedNode;
use CirrusSearch\Parser\AST\WordsQueryNode;
use CirrusSearch\Search\Escaper;

/**
 * @covers \CirrusSearch\Parser\QueryStringRegex\NonPhraseParser
 * @group CirrusSearch
 */
class NonPhraseParserTest extends CirrusTestCase {
	/**
	 * @dataProvider provideWordQueries
	 * @param string $query
	 * @param int $start
	 * @param ParsedNode|null $expected
	 */
	public function testWord( $query, $start, $expected ) {
		$parser = new NonPhraseParser( new Escaper( 'en', false ) );
		$nodes = $parser->parse( $query, $start );
		$this->assertEquals( $expected, $nodes );
	}

	public function provideWordQueries() {
		return [
			'simple' => [
				'this is just"something"',
				0,
				new WordsQueryNode( 0,
					strlen( 'this' ),
					'this' )
			],
			'negated phrase (bis)' => [
				'just-"something"',
				0,
				new WordsQueryNode( 0,
					strlen( 'just' ),
					'just' )
			],
			'collapsed' => [
				'just"something"',
				0,
				new WordsQueryNode( 0,
					strlen( 'just' ),
					'just' )
			],
			'collapsed negation' => [
				'just!"something"',
				0,
				new WordsQueryNode( 0,
					strlen( 'just' ),
					'just' )
			],
			'escaped quote phrase' => [
				'just\\"something"',
				0,
				new WordsQueryNode( 0,
					strlen( 'just\\"something' ),
					'just"something' )
			],
			'escaped negation' => [
				'just\\!"something"',
				0,
				new WordsQueryNode( 0,
					strlen( 'just !' ),
					'just!' )
			],
			'escaped negation (bis)' => [
				'just\\-"something"',
				0,
				new WordsQueryNode( 0,
					strlen( 'just\\-' ),
					'just-' )
			],
			'escape escape sequence and negation' => [
				'just\\\\!"something"',
				0,
				new WordsQueryNode( 0,
					strlen( 'just\\\\' ),
					'just\\' )
			],
			'escape escape sequence and negation (bis)' => [
				'just\\\\-"something"',
				0,
				new WordsQueryNode( 0,
					strlen( 'just\\\\' ),
					'just\\' )
			],
			'ends with dash' => [
				'just-',
				0,
				new WordsQueryNode( 0,
					strlen( 'just-' ),
					'just-' )
			],
			'ends with excl' => [
				'just!',
				0,
				new WordsQueryNode( 0,
					strlen( 'just!' ),
					'just!' )
			],
			'ends with escape sequence' => [
				'just\\',
				0,
				new WordsQueryNode( 0,
					strlen( 'just\\' ),
					'just\\' )
			],
			'ends with ! and escaped dquotes' => [
				'just!\\"',
				0,
				new WordsQueryNode( 0,
					strlen( 'just!\\"' ),
					'just!"' )
			],
			'ends with double escape and dquotes' => [
				'just\\\\"',
				0,
				new WordsQueryNode( 0,
					strlen( 'just\\\\' ),
					'just\\' )
			]
		];
	}
}
