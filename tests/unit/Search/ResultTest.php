<?php

namespace CirrusSearch\Search;

use CirrusSearch\CirrusTestCase;
use MediaWiki\Mediawikiservices;

/**
 * @group CirrusSearch
 */
class ResultTest extends CirrusTestCase {
	public function testInterwikiResults() {
		$this->setMwGlobals( [
			'wgCirrusSearchWikiToNameMap' => [
				'es' => 'eswiki',
			],
		] );
		$config = MediaWikiServices::getInstance()
			->getConfigFactory()
			->makeConfig( 'CirrusSearch' );

		$elasticaResultSet = $this->getMockBuilder( \Elastica\ResultSet::class )
			->disableOriginalConstructor()
			->getMock();

		$data = [
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
		$elasticaResult = new \Elastica\Result( $data );
		$result = new Result(
			$elasticaResultSet,
			$elasticaResult,
			$config
		);

		$this->assertTrue( $result->getTitle()->isExternal(), 'isExternal' );
		$this->assertTrue( $result->getRedirectTitle()->isExternal(), 'redirect isExternal' );
		$this->assertTrue( $result->getSectionTitle()->isExternal(), 'section title isExternal' );

		// Test that we can't build the redirect title if the namespaces
		// do not match
		$data['_source']['namespace'] = NS_HELP;
		$data['_source']['namespace_text'] = 'Help';
		$elasticaResult = new \Elastica\Result( $data );

		$result = new Result(
			$elasticaResultSet,
			$elasticaResult,
			$config
		);

		$this->assertTrue( $result->getTitle()->isExternal(), 'isExternal namespace mismatch' );
		$this->assertEquals( $result->getTitle()->getPrefixedText(), 'es:Help:Main Page' );
		$this->assertTrue( $result->getRedirectTitle() === null, 'redirect is not built with ns mismatch' );
		$this->assertTrue( $result->getSectionTitle()->isExternal(), 'section title isExternal' );
	}
}
