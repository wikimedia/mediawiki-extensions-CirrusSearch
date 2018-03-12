<?php

namespace CirrusSearch\Parser\QueryStringRegex;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Parser\AST\KeywordFeatureNode;
use CirrusSearch\Parser\AST\NegatedNode;
use CirrusSearch\Query\InSourceFeature;
use CirrusSearch\Query\InTitleFeature;
use CirrusSearch\Query\LocalFeature;
use CirrusSearch\Query\MoreLikeFeature;
use CirrusSearch\Query\PrefixFeature;
use CirrusSearch\SearchConfig;

/**
 * @covers \CirrusSearch\Parser\QueryStringRegex\KeywordParser
 */
class KeywordParserTest extends CirrusTestCase {
	public function testSimple() {
		$parser = new KeywordParser();
		// .      0000000000111111111122222222223333333333
		// .      0123456789012345678901234567890123456789
		$query = 'intitle:test foo bar -intitle:"hop\"foo" ';
		$nodes = $parser->parse( $query, new InTitleFeature( new SearchConfig() ), new OffsetTracker() );
		$this->assertEquals( 2, count( $nodes ) );

		/** @var KeywordFeatureNode $kw */
		$kw = $nodes[0];
		$this->assertInstanceOf( KeywordFeatureNode::class, $kw );
		$this->assertEquals( 0, $kw->getStartOffset() );
		$this->assertEquals( 12, $kw->getEndOffset() );
		$this->assertEquals( '', $kw->getDelimiter() );
		$this->assertEquals( '', $kw->getSuffix() );
		$this->assertEquals( 'intitle', $kw->getKey() );
		$this->assertEquals( 'test', $kw->getValue() );
		$this->assertEquals( 'test', $kw->getQuotedValue() );

		/** @var NegatedNode $kw */
		$kw = $nodes[1];
		$this->assertInstanceOf( NegatedNode::class, $kw );
		$this->assertEquals( 21, $kw->getStartOffset() );
		$this->assertEquals( 40, $kw->getEndOffset() );

		$kw = $kw->getChild();
		/** @var KeywordFeatureNode $kw */
		$this->assertInstanceOf( KeywordFeatureNode::class, $kw );
		$this->assertEquals( 22, $kw->getStartOffset() );
		$this->assertEquals( 40, $kw->getEndOffset() );
		$this->assertEquals( '"', $kw->getDelimiter() );
		$this->assertEquals( '', $kw->getSuffix() );
		$this->assertEquals( 'intitle', $kw->getKey() );
		$this->assertEquals( 'hop"foo', $kw->getValue() );
		$this->assertEquals( '"hop\"foo"', $kw->getQuotedValue() );
	}

	public function testGreedyHeader() {
		$parser = new KeywordParser();
		// .      0000000000111111111122222222223333333333
		// .      0123456789012345678901234567890123456789
		$query = ' morelike:"test foo " bar ';
		$nodes = $parser->parse( $query, new MoreLikeFeature( new SearchConfig() ), new OffsetTracker() );
		$this->assertEquals( 1, count( $nodes ) );

		$kw = $nodes[0];
		$this->assertEquals( 1, $kw->getStartOffset() );
		$this->assertEquals( 26, $kw->getEndOffset() );
		$this->assertEquals( '', $kw->getDelimiter() );
		$this->assertEquals( '', $kw->getSuffix() );
		$this->assertEquals( 'morelike', $kw->getKey() );
		$this->assertEquals( '"test foo " bar ', $kw->getValue() );
		$this->assertEquals( '"test foo " bar ', $kw->getQuotedValue() );
	}

	public function testGreedy() {
		$parser = new KeywordParser();
		// .      0000000000111111111122222222223333333333
		// .      0123456789012345678901234567890123456789
		$query = ' prefix:"test foo " bar ';
		$nodes = $parser->parse( $query, new PrefixFeature(), new OffsetTracker() );
		$this->assertEquals( 1, count( $nodes ) );

		$kw = $nodes[0];
		$this->assertEquals( 1, $kw->getStartOffset() );
		$this->assertEquals( 24, $kw->getEndOffset() );
		$this->assertEquals( '', $kw->getDelimiter() );
		$this->assertEquals( '', $kw->getSuffix() );
		$this->assertEquals( 'prefix', $kw->getKey() );
		$this->assertEquals( '"test foo " bar ', $kw->getValue() );
		$this->assertEquals( '"test foo " bar ', $kw->getQuotedValue() );
	}

	public function testHeader() {
		$parser = new KeywordParser();
		// .      0000000000111111111122222222223333333333
		// .      0123456789012345678901234567890123456789
		$query = ' local:local:"test foo " bar ';
		$nodes = $parser->parse( $query, new LocalFeature(), new OffsetTracker() );
		$this->assertEquals( 2, count( $nodes ) );

		$kw = $nodes[0];
		$this->assertEquals( 1, $kw->getStartOffset() );
		$this->assertEquals( 7, $kw->getEndOffset() );
		$this->assertEquals( '', $kw->getDelimiter() );
		$this->assertEquals( '', $kw->getSuffix() );
		$this->assertEquals( 'local', $kw->getKey() );
		$this->assertEquals( '', $kw->getValue() );
		$this->assertEquals( '', $kw->getQuotedValue() );
		// FIXME: figure out if this is the right behavior
		$kw = $nodes[1];
		$this->assertEquals( 7, $kw->getStartOffset() );
		$this->assertEquals( 13, $kw->getEndOffset() );
		$this->assertEquals( '', $kw->getDelimiter() );
		$this->assertEquals( '', $kw->getSuffix() );
		$this->assertEquals( 'local', $kw->getKey() );
		$this->assertEquals( '', $kw->getValue() );
		$this->assertEquals( '', $kw->getQuotedValue() );
	}

	public function testRegex() {
		$parser = new KeywordParser();
		// .      00000000001111111111222222 22223333333333
		// .      01234567890123456789012345 67890123456789
		$query = ' unrelated insource:/test\\/"/i ';
		$config = new HashSearchConfig( [
			'CirrusSearchEnableRegex' => false,
		], [ 'inherit' ] );

		$nodes = $parser->parse( $query, new InSourceFeature( $config ), new OffsetTracker() );
		$this->assertEquals( 1, count( $nodes ) );

		$kw = $nodes[0];
		$this->assertEquals( 11, $kw->getStartOffset() );
		$this->assertEquals( 30, $kw->getEndOffset() );
		$this->assertEquals( '/', $kw->getDelimiter() );
		$this->assertEquals( 'i', $kw->getSuffix() );
		$this->assertEquals( 'insource', $kw->getKey() );
		$this->assertEquals( 'test/"', $kw->getValue() );
		$this->assertEquals( '/test\\/"/', $kw->getQuotedValue() );
	}
}
