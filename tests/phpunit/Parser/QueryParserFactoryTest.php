<?php

namespace CirrusSearch\Parser;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Parser\QueryStringRegex\QueryStringRegexParser;

/**
 * @covers \CirrusSearch\Parser\QueryParserFactory
 */
class QueryParserFactoryTest extends CirrusTestCase {

	public function provideConfig() {
		return [
			'CirrusSearchAllowLeadingWildcard changes parsing behaviors' => [
				[ 'CirrusSearchAllowLeadingWildcard' => true ],
				'*help',
				false
			],
			'LanguageCode changes parsing behaviors' => [
				[ 'LanguageCode' => 'he' ],
				'gershayi"m',
				false
			],
			'CirrusSearchStripQuestionMarks changes parsing behaviors' => [
				[ 'CirrusSearchStripQuestionMarks' => 'all' ],
				'Will this pass?',
				false
			],
			'CirrusSearchEnableRegex changes parsing behaviors' => [
				[ 'CirrusSearchEnableRegex' => true ],
				'intitle:/findit/',
				false
			],
			'CirrusSearchUpdateShardTimeout does not change parsing behaviors' => [
				[ 'CirrusSearchUpdateShardTimeout' => '20h' ],
				"not sure what I'm looking for",
				true
			]
		];
	}

	/**
	 * Basic test to ensure that the config is properly propagated by the factory.
	 *
	 * @dataProvider provideConfig
	 */
	public function test( $config, $query, $equals ) {
		$parser = QueryParserFactory::newFullTextQueryParser( new HashSearchConfig( [] ) );
		$this->assertInstanceOf( QueryStringRegexParser::class, $parser );
		$this->assertEquals( $parser,
			QueryParserFactory::newFullTextQueryParser( new HashSearchConfig( [] ) ),
			'Same config should build identical parser' );

		$emptyConfigParsedQuery = $parser->parse( $query );

		$updatedConfigParsedQuery = QueryParserFactory::newFullTextQueryParser( new HashSearchConfig( $config ) )
			->parse( $query );

		if ( $equals ) {
			$this->assertEquals( $emptyConfigParsedQuery->toArray(), $updatedConfigParsedQuery->toArray() );
		} else {
			$this->assertNotEquals( $emptyConfigParsedQuery->toArray(), $updatedConfigParsedQuery->toArray() );
		}
	}
}
