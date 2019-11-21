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
 * @covers \CirrusSearch\Parser\QueryStringRegex\QueryStringRegexParser
 * @covers \CirrusSearch\Parser\QueryStringRegex\SearchQueryParseException
 */
class QueryStringRegexParserTest extends CirrusTestCase {

	public function testEmpty() {
		$config = new HashSearchConfig( [], [ HashSearchConfig::FLAG_INHERIT ] );

		$parser = $this->buildParser( $config );
		$this->assertEquals( new EmptyQueryNode( 0, 0 ), $parser->parse( '' )->getRoot() );
	}

	public function testLastUnbalanced() {
		$config = new HashSearchConfig( [] );

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

	public function testMaxLength() {
		$config = new HashSearchConfig( [ 'CirrusSearchMaxFullTextQueryLength' => 10 ] );

		$parser = $this->buildParser( $config );
		try {
			$parser->parse( str_repeat( "a", 11 ) );
			$this->fail( "Expected exception" );
		} catch ( SearchQueryParseException $e ) {
			$this->assertEquals( $e->asStatus(),
				\Status::newFatal( 'cirrussearch-query-too-long', 11, 10 ) );
		}

		$q = "incategory:test " . str_repeat( "a", 10 );
		try {
			$parser->parse( "incategory:test " . str_repeat( "a", 10 ) );
			$this->fail( "Expected exception" );
		} catch ( SearchQueryParseException $e ) {
			// The allowed query length is what is the configured limit + the size occupied by incategory keywords
			$this->assertEquals( $e->asStatus(),
				\Status::newFatal( 'cirrussearch-query-too-long',
					mb_strlen( $q ), 10 + mb_strlen( "incategory:test" ) ) );
		}

		// the use of the incategory keyword inhibits the size check
		$parser->parse( "incategory:test " . str_repeat( "a", 9 ) );
	}

	public function testHardLimitOnQueryLength() {
		// Test that even if we allow more than the hard limit, the hard limit is always applied because evaluated prior any parsing steps
		$config = new HashSearchConfig( [ 'CirrusSearchMaxFullTextQueryLength' => QueryStringRegexParser::QUERY_LEN_HARD_LIMIT * 2 ] );

		$parser = $this->buildParser( $config );
		/** @var ParsedBooleanNode $parsedNode */
		try {
			$parser->parse( str_repeat( "a", QueryStringRegexParser::QUERY_LEN_HARD_LIMIT + 1 ) );
			$this->fail( "Expected exception" );
		} catch ( SearchQueryParseException $e ) {
			$this->assertEquals( $e->asStatus(),
				\Status::newFatal( 'cirrussearch-query-too-long',
					QueryStringRegexParser::QUERY_LEN_HARD_LIMIT + 1,
					QueryStringRegexParser::QUERY_LEN_HARD_LIMIT ) );
		}
	}

	/**
	 * @param SearchConfig $config
	 * @return QueryParser
	 */
	public function buildParser( $config ) {
		$parser = QueryParserFactory::newFullTextQueryParser( $config, $this->namespacePrefixParser() );
		$this->assertInstanceOf( QueryStringRegexParser::class, $parser );
		return $parser;
	}
}
