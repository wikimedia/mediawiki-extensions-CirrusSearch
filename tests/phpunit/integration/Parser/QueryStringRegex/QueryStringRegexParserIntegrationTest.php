<?php

namespace CirrusSearch\Parser\QueryStringRegex;

use CirrusSearch\CirrusIntegrationTestCase;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Parser\QueryParser;
use CirrusSearch\SearchConfig;

/**
 * @covers \CirrusSearch\Parser\AST\BooleanClause
 * @covers \CirrusSearch\Parser\AST\ParsedQuery
 * @covers \CirrusSearch\Parser\AST\ParsedNode
 * @covers \CirrusSearch\Parser\AST\ParseWarning
 * @covers \CirrusSearch\Parser\AST\ParsedBooleanNode
 * @covers \CirrusSearch\Parser\AST\NegatedNode
 * @covers \CirrusSearch\Parser\AST\KeywordFeatureNode
 * @covers \CirrusSearch\Parser\AST\PrefixNode
 * @covers \CirrusSearch\Parser\AST\PhrasePrefixNode
 * @covers \CirrusSearch\Parser\AST\PhraseQueryNode
 * @covers \CirrusSearch\Parser\AST\WordsQueryNode
 * @covers \CirrusSearch\Parser\AST\EmptyQueryNode
 * @covers \CirrusSearch\Parser\AST\WildcardNode
 * @covers \CirrusSearch\Parser\AST\FuzzyNode
 * @covers \CirrusSearch\Parser\AST\NamespaceHeaderNode
 * @covers \CirrusSearch\Parser\QueryStringRegex\PhraseQueryParser
 * @covers \CirrusSearch\Parser\QueryStringRegex\NonPhraseParser
 * @covers \CirrusSearch\Parser\QueryStringRegex\OffsetTracker
 * @covers \CirrusSearch\Parser\QueryStringRegex\KeywordParser
 * @covers \CirrusSearch\Parser\QueryStringRegex\QueryStringRegexParser
 * @covers \CirrusSearch\Parser\QueryStringRegex\Token
 * @covers \CirrusSearch\Parser\FTQueryClassifiersRepository
 * @covers \CirrusSearch\Parser\BasicQueryClassifier
 * @group CirrusSearch
 */
class QueryStringRegexParserIntegrationTest extends CirrusIntegrationTestCase {

	private const FIXTURE_FILE = 'regexParser/ref_impl_fixtures.json';

	/**
	 * @dataProvider provideRefImplQueries
	 * @throws \CirrusSearch\Parser\ParsedQueryClassifierException
	 */
	public function testRefImplFixtures( array $expected, $queryString, array $config = [] ) {
		$this->assertQuery( $expected, $queryString, $config );
	}

	/**
	 * @param array $expected
	 * @param string $queryString
	 * @param array $config
	 * @throws \CirrusSearch\Parser\ParsedQueryClassifierException
	 */
	public function assertQuery( array $expected, $queryString, array $config = [] ) {
		$this->overrideConfigValues( [ 'CapitalLinks' => true ] );
		$config = new HashSearchConfig(
			$config + [ 'CirrusSearchStripQuestionMarks' => 'all' ],
			[ HashSearchConfig::FLAG_INHERIT ]
		);
		$parser = $this->buildParser( $config );
		$parsedQuery = $parser->parse( $queryString );
		$actual = $parsedQuery->toArray();
		$this->assertEquals( $expected, $actual, true );
	}

	private static function mustRegenParserTests( string $filename ): bool {
		return getenv( 'REGEN_PARSER_TESTS' ) === $filename || getenv( 'REGEN_PARSER_TESTS' ) === 'all';
	}

	public function provideRefImplQueries() {
		return $this->provideQueries( self::FIXTURE_FILE );
	}

	public function provideQueries( $filename ) {
		if ( self::mustRegenParserTests( $filename ) ) {
			return [];
		}
		$tests = CirrusIntegrationTestCase::loadFixture( $filename );
		foreach ( $tests as $test => $data ) {
			if ( !isset( $data['expected'] ) ) {
				$this->fail( "Expected data not found for test $test, please regenerate this fixture " .
					"file by setting REGEN_PARSER_TESTS=$filename" );
			}
			yield $test => [
				$data['expected'],
				$data['query'],
				$data['config'] ?? []
			];
		}
	}

	public static function provideRegenParserTests() {
		yield "Regen " . self::FIXTURE_FILE => [ self::FIXTURE_FILE ];
	}

	/**
	 * @dataProvider provideRegenParserTests
	 */
	public function testRegenParserTests( string $filename ): void {
		if ( self::mustRegenParserTests( $filename ) ) {
			$tests = CirrusIntegrationTestCase::loadFixture( $filename );
			$ntests = [];
			foreach ( $tests as $name => $data ) {
				$ntest = [];
				$ntest['query'] = $data['query'];
				$config = [];
				if ( !empty( $data['config'] ) ) {
					$config = $data['config'];
					$ntest['config'] = $config;
				}
				$query = $this->parse( $data['query'], $config );
				$ntest['expected'] = $query->toArray();
				$ntests[$name] = $ntest;
			}
			CirrusIntegrationTestCase::saveFixture( $filename, $ntests );
		}
		$this->assertTrue( $this->hasFixture( $filename ) );
	}

	private function parse( $query, $config ) {
		$config = new HashSearchConfig(
			$config + [ 'CirrusSearchStripQuestionMarks' => 'all' ],
			[ HashSearchConfig::FLAG_INHERIT ]
		);

		return $this->buildParser( $config )->parse( $query );
	}

	/**
	 * @param SearchConfig $config
	 * @return QueryParser
	 */
	public function buildParser( $config ) {
		$parser = $this->createNewFullTextQueryParser( $config );
		$this->assertInstanceOf( QueryStringRegexParser::class, $parser );
		return $parser;
	}

}
