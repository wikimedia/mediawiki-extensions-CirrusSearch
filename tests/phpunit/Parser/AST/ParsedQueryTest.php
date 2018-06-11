<?php

namespace CirrusSearch\Parser\AST;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\CrossSearchStrategy;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Parser\FullTextKeywordRegistry;
use CirrusSearch\Parser\QueryStringRegex\QueryStringRegexParser;
use CirrusSearch\Search\Escaper;

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
		$config = new HashSearchConfig( [] );
		$parser = new QueryStringRegexParser( new FullTextKeywordRegistry( $config ),
			new Escaper( 'en', true ), 'all' );
		$pQuery = $parser->parse( $query );
		$this->assertEquals( $expectedStratery, $pQuery->getCrossSearchStrategy() );
	}
}
