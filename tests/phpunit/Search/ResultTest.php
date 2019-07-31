<?php

namespace CirrusSearch\Search;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\Searcher;
use MediaWiki\MediaWikiServices;
use Sanitizer;

/**
 * @group CirrusSearch
 * @covers \CirrusSearch\Search\Result
 * @covers \CirrusSearch\Search\CirrusSearchResult
 */
class ResultTest extends CirrusTestCase {

	// @TODO In php 5.6 this could be a constant
	private function exampleHit() {
		return [
			'_index' => 'eswiki_content_123456',
			'_source' => [
				'namespace' => NS_MAIN,
				'namespace_text' => '',
				'title' => 'Main Page',
				'wiki' => 'eswiki',
				'redirect' => [
					[
						'title' => 'Main',
						'namespace' => NS_MAIN,
					],
				],
			],
			'highlight' => [
				'redirect.title' => [ 'Main' ],
				'heading' => [ '...' ],
			],
		];
	}

	public function highlightedSectionSnippetProvider() {
		return [
			'stuff' => [ [ '', 'stuff', '' ] ],
			// non-ASCII encoding of "fragment" is ugly, so test on easier
			// German case
			'german' => [ [ '', 'tschüß', '' ] ],
			// English combining umlaut should move from post to highlight
			'english' => [ [ 'Sp', 'ın', '̈al' ], [ 'Sp', 'ın̈', 'al' ] ],
			// Hindi combining vowel mark should move from post to highlight
			'hindi' => [ [ '', 'म', 'ेला' ], [ '', 'मे', 'ला' ] ],
			// Javanese final full character in pre should move to highlight
			// to join consonant mark; vowel mark in post should move to highlight
			'javanese' => [ [ 'ꦎꦂꦠꦺꦴꦒ', 'ꦿꦥ꦳', 'ꦶ' ], [ 'ꦎꦂꦠꦺꦴ', 'ꦒꦿꦥ꦳ꦶ', '' ] ],
			// Myanmar final full character in pre and two post combining marks
			// should move to highlight
			'myanmar' => [ [ 'ခင်ဦးမ', 'ြိ', 'ု့နယ်' ], [ 'ခင်ဦး', 'မြို့', 'နယ်' ] ],
			// Full character and combining mark should move from pre to highlight
			// to join combining mark; post combining marks should move to highlight
			'wtf' => [ [ 'Q̃̓', '̧̑', '̫̯' ], [ '', 'Q̧̫̯̃̓̑', '' ] ],
		];
	}

	/**
	 * @dataProvider highlightedSectionSnippetProvider
	 */
	public function testHighlightedSectionSnippet( array $input, array $output = [], $plain = '' ) {
		// If no output segementation is specified, it should break up the same as the input.
		if ( empty( $output ) ) {
			$output = $input;
		}
		// If no plain version is specified, join the input together.
		if ( $plain === '' ) {
			$plain = implode( '', $input );
		}

		// Input has PRE/POST_MARKER character; output has PRE/POST HTML.
		$elasticInput = $input[0] . Searcher::HIGHLIGHT_PRE_MARKER . $input[1] . Searcher::HIGHLIGHT_POST_MARKER . $input[2];
		$htmlOutput = $output[0] . Searcher::HIGHLIGHT_PRE . $output[1] . Searcher::HIGHLIGHT_POST . $output[2];

		$data = $this->exampleHit();
		$data['highlight']['heading'] = [ $elasticInput ];

		$result = $this->mockResult( $data );
		$this->assertEquals( $htmlOutput, $result->getSectionSnippet() );
		$this->assertEquals( Sanitizer::escapeIdForLink( $plain ),
			$result->getSectionTitle()->getFragment() );
	}

	public function testInterwikiResults() {
		$this->setMwGlobals( [
			'wgCirrusSearchWikiToNameMap' => [
				'es' => 'eswiki',
			],
		] );

		$data = $this->exampleHit();
		$result = $this->mockResult( $data );

		$this->assertTrue( $result->getTitle()->isExternal(), 'isExternal' );
		$this->assertTrue( $result->getRedirectTitle()->isExternal(), 'redirect isExternal' );
		$this->assertTrue( $result->getSectionTitle()->isExternal(), 'section title isExternal' );

		// Test that we can't build the redirect title if the namespaces
		// do not match
		$data['_source']['namespace'] = NS_HELP;
		$data['_source']['namespace_text'] = 'Help';
		$result = $this->mockResult( $data );

		$this->assertTrue( $result->getTitle()->isExternal(), 'isExternal namespace mismatch' );
		$this->assertEquals( $result->getTitle()->getPrefixedText(), 'es:Help:Main Page' );
		$this->assertTrue( $result->getRedirectTitle() === null, 'redirect is not built with ns mismatch' );
		$this->assertTrue( $result->getSectionTitle()->isExternal(), 'section title isExternal' );
	}

	private function mockResult( $hit ) {
		$config = MediaWikiServices::getInstance()
			->getConfigFactory()
			->makeConfig( 'CirrusSearch' );

		$elasticaResultSet = $this->getMockBuilder( \Elastica\ResultSet::class )
			->disableOriginalConstructor()
			->getMock();

		return new Result(
			$elasticaResultSet,
			new \Elastica\Result( $hit ),
			$config
		);
	}
}
