<?php

namespace CirrusSearch\Search;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\Searcher;
use MediaWiki\MediaWikiServices;

/**
 * @group CirrusSearch
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

	public function testHighlightedSectionSnippet() {
		$data = $this->exampleHit();
		$data['highlight']['heading'] = [ Searcher::HIGHLIGHT_PRE_MARKER . 'stuff' . Searcher::HIGHLIGHT_POST_MARKER ];

		$result = $this->mockResult( $data );
		$this->assertEquals(
			Searcher::HIGHLIGHT_PRE . 'stuff' . Searcher::HIGHLIGHT_POST,
			$result->getSectionSnippet()
		);
		$this->assertEquals(
			'stuff',
			$result->getSectionTitle()->getFragment()
		);
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
