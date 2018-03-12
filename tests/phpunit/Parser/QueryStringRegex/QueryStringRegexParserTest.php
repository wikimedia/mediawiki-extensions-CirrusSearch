<?php

namespace CirrusSearch\Parser\QueryStringRegex;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Parser\AST\EmptyQueryNode;
use CirrusSearch\Parser\AST\ParsedBooleanNode;
use CirrusSearch\Parser\AST\PhraseQueryNode;
use CirrusSearch\Parser\FullTextKeywordRegistry;
use CirrusSearch\Search\Escaper;
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
 * @covers \CirrusSearch\Parser\QueryParser
 * @covers \CirrusSearch\Parser\QueryStringRegex\PhraseQueryParser
 * @covers \CirrusSearch\Parser\QueryStringRegex\NonPhraseParser
 * @covers \CirrusSearch\Parser\QueryStringRegex\OffsetTracker
 * @covers \CirrusSearch\Parser\QueryStringRegex\KeywordParser
 * @covers \CirrusSearch\Parser\QueryStringRegex\QueryStringRegexParser
 * @covers \CirrusSearch\Parser\QueryStringRegex\Token
 * @group CirrusSearch
 */
class QueryStringRegexParserTest extends CirrusTestCase {

	/**
	 * @dataProvider provideRefImplQueries
	 * @param array $config
	 * @param $expected
	 * @param $queryString
	 */
	public function testRefImplFixtures( $expected, $queryString, array $config = [] ) {
		$this->assertQuery( $expected, $queryString, $config );
	}

	/**
	 * @param array $config
	 * @param $expected
	 * @param $queryString
	 */
	public function assertQuery( $expected, $queryString, array $config = [] ) {
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
		$file = __DIR__ . '/../../fixtures/regexParser/' . $filename;
		$tests = json_decode( file_get_contents( $file ), JSON_OBJECT_AS_ARRAY );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			throw new \RuntimeException( "Failed parsing query fixture: $file" );
		}
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
				$ntest['expected'] = $this->parse( $data['query'], $config )->toArray();
				$ntests[$name] = $ntest;
			}
			file_put_contents( $file, json_encode( $ntests, JSON_PRETTY_PRINT ) );
			return [];
		}
		$unittests = [];
		foreach ( $tests as $test => $data ) {
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
	 * @return QueryStringRegexParser
	 */
	public function buildParser( $config ) {
		$escaper =
			new Escaper( $config->get( 'LanguageCode' ),
				$config->get( 'CirrusSearchAllowLeadingWildcard' ) );

		$parser =
			new QueryStringRegexParser( new FullTextKeywordRegistry( $config ), $escaper,
				$config->get( 'CirrusSearchStripQuestionMarks' ) );

		return $parser;
	}
}
