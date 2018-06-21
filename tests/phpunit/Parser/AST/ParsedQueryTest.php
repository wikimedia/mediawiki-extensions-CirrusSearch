<?php

namespace CirrusSearch\Parser\AST;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\CrossSearchStrategy;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Parser\QueryParserFactory;

/**
 * @covers \CirrusSearch\Parser\AST\ParsedQuery
 * @group CirrusSearch
 */
class ParsedQueryTest extends CirrusTestCase {

	public function provideQueriesForTestingCrossSearchStrategy() {
		return [
			'simple' => [
				'',
				CrossSearchStrategy::allWikisStrategy()
			],
			'words' => [
				'foo bar',
				CrossSearchStrategy::allWikisStrategy()
			],
			'one keyword' => [
				'intitle:foo',
				CrossSearchStrategy::allWikisStrategy()
			],
			'multiple keywords with host wiki only strategy' => [
				'intitle:foo incategory:test incategory:id:123',
				CrossSearchStrategy::hostWikiOnlyStrategy()
			]
		];
	}

	/**
	 * @dataProvider provideQueriesForTestingCrossSearchStrategy
	 * @covers \CirrusSearch\Parser\AST\ParsedQuery::getCrossSearchStrategy()
	 */
	public function testCrossSearchStrategy( $query, CrossSearchStrategy $expectedStratery ) {
		$parser = QueryParserFactory::newFullTextQueryParser( new HashSearchConfig( [] ) );
		$pQuery = $parser->parse( $query );
		$this->assertEquals( $expectedStratery, $pQuery->getCrossSearchStrategy() );
	}

	public function provideTestFeaturesUsed() {
		return [
			'none' => [
				'query',
				[]
			],
			'simple' => [
				'intitle:test',
				[ 'intitle' ],
			],
			'multiple' => [
				'intitle:test intitle:foo incategory:test',
				[ 'intitle', 'incategory' ],
			],
			'morelike' => [
				'morelike:test',
				[ 'more_like' ],
			],
			'regex' => [
				'intitle:/test/ insource:/test/',
				[ 'regex' ],
			]
		];
	}

	/**
	 * @dataProvider provideTestFeaturesUsed
	 * @param string $query
	 * @param string[] $features
	 */
	public function testFeaturesUsed( $query, array $features ) {
		$config = new HashSearchConfig( [ 'CirrusSearchEnableRegex' => true ] );
		$parser = QueryParserFactory::newFullTextQueryParser( $config );
		$parsedQuery = $parser->parse( $query );
		$this->assertArrayEquals( $features, $parsedQuery->getFeaturesUsed() );
	}
}
