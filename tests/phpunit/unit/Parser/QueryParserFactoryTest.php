<?php

namespace CirrusSearch\Parser;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\Parser\QueryStringRegex\QueryStringRegexParser;
use CirrusSearch\Parser\QueryStringRegex\SearchQueryParseException;

/**
 * @covers \CirrusSearch\Parser\QueryParserFactory
 */
class QueryParserFactoryTest extends CirrusTestCase {

	public static function provideConfig() {
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
			],
			'CirrusSearchMaxFullTextQueryLength changes parsing behaviors' => [
				[ 'CirrusSearchMaxFullTextQueryLength' => 10 ],
				"not sure what I'm looking for",
				false
			],
		];
	}

	/**
	 * Basic test to ensure that the config is properly propagated by the factory.
	 *
	 * @dataProvider provideConfig
	 */
	public function test( $config, $query, $equals ) {
		$parser = $this->createNewFullTextQueryParser( $this->newHashSearchConfig( [] ) );
		$this->assertInstanceOf( QueryStringRegexParser::class, $parser );
		$this->assertEquals( $parser,
			$this->createNewFullTextQueryParser( $this->newHashSearchConfig( [] ) ),
			'Same config should build identical parser' );

		try {
			$emptyConfigParsedQuery = $parser->parse( $query );
			$emptyConfigParsedQuery = $emptyConfigParsedQuery->toArray();
		} catch ( SearchQueryParseException $e ) {
			$emptyConfigParsedQuery = $e;
		}

		try {
			$updatedConfigParsedQuery = $this->createNewFullTextQueryParser( $this->newHashSearchConfig( $config ) )
				->parse( $query );
			$updatedConfigParsedQuery = $updatedConfigParsedQuery->toArray();
		} catch ( SearchQueryParseException $e ) {
			$updatedConfigParsedQuery = $e;
		}

		if ( $equals ) {
			$this->assertEquals( $emptyConfigParsedQuery, $updatedConfigParsedQuery );
		} else {
			$this->assertNotEquals( $emptyConfigParsedQuery, $updatedConfigParsedQuery );
		}
	}
}
