<?php

namespace CirrusSearch\Parser\QueryStringRegex;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Parser\AST\EmptyQueryNode;
use CirrusSearch\Parser\AST\ParsedBooleanNode;
use CirrusSearch\Parser\AST\PhraseQueryNode;
use CirrusSearch\Parser\QueryParser;
use CirrusSearch\Parser\QueryParserFactory;
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
 * @covers \CirrusSearch\Parser\QueryParser
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
class QueryStringRegexParserTest extends CirrusTestCase {

	/**
	 * @dataProvider provideRefImplQueries
	 * @param array $expected
	 * @param array $config
	 * @param string $queryString
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
		$config = new HashSearchConfig(
			$config + [ 'CirrusSearchStripQuestionMarks' => 'all' ],
			[ 'inherit' ]
		);
		$parser = $this->buildParser( $config );
		$parsedQuery = $parser->parse( $queryString );
		$actual = $parsedQuery->toArray();
		$this->assertEquals( $expected, $actual, true );
	}

	public function provideRefImplQueries() {
		return $this->provideQueries( 'ref_impl_fixtures.json' );
	}

	public function provideQueries( $filename ) {
		$file = 'regexParser/' . $filename;
		$tests = CirrusTestCase::loadFixture( $file );
		if ( getenv( 'REGEN_PARSER_TESTS' ) === $filename || getenv( 'REGEN_PARSER_TESTS' ) === 'all' ) {
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
			CirrusTestCase::saveFixture( $file, $ntests );
			return [];
		}
		$unittests = [];
		foreach ( $tests as $test => $data ) {
			if ( !isset( $data['expected'] ) ) {
				$this->fail( "Expected data not found for test $test, please regenerate this fixture " .
					"file by setting REGEN_PARSER_TESTS=$filename" );
			}
			$unittests[$test] = [
				$data['expected'],
				$data['query'],
				isset( $data['config'] ) ? $data['config'] : []
			];
		}
		return $unittests;
	}

	private function parse( $query, $config ) {
		$config = new HashSearchConfig(
			$config + [ 'CirrusSearchStripQuestionMarks' => 'all' ],
			[ 'inherit' ]
		);

		return $this->buildParser( $config )->parse( $query );
	}

	public function testEmpty() {
		$config = new HashSearchConfig( [], [ 'inherit' ] );

		$parser = $this->buildParser( $config );
		$this->assertEquals( new EmptyQueryNode( 0, 0 ), $parser->parse( '' )->getRoot() );
	}

	public function testLastUnbalanced() {
		$config = new HashSearchConfig( [], [ 'inherit' ] );

		$parser = $this->buildParser( $config );
		/** @var ParsedBooleanNode $parsedNode */
		$parsedNode = $parser->parse( 'test "' )->getRoot();
		$this->assertInstanceOf( ParsedBooleanNode::class, $parsedNode );
		$this->assertEquals( 2, count( $parsedNode->getClauses() ) );
		/** @var PhraseQueryNode $phraseNode */
		$phraseNode = $parsedNode->getClauses()[1]->getNode();
		$this->assertInstanceOf( PhraseQueryNode::class, $phraseNode );
		$this->assertEquals( '', $phraseNode->getPhrase() );
	}

	/**
	 * @param SearchConfig $config
	 * @return QueryParser
	 */
	public function buildParser( $config ) {
		$parser = QueryParserFactory::newFullTextQueryParser( $config );
		$this->assertInstanceOf( QueryStringRegexParser::class, $parser );
		return $parser;
	}
}
