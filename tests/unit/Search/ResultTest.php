<?php

namespace CirrusSearch\Search;

use CirrusSearch\CirrusTestCase;

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

		$elasticaResultSet = $this->getMockBuilder( \Elastica\ResultSet::class )
			->disableOriginalConstructor()
			->getMock();

		$elasticaResult = new \Elastica\Result( [
			'_index' => 'eswiki_content_123456',
			'_source' => [
				'namespace' => NS_MAIN,
				'title' => 'Main Page',
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
		] );
		$result = new Result( $elasticaResultSet, $elasticaResult, 'es' );

		$this->assertTrue( $result->getTitle()->isExternal() );
		$this->assertTrue( $result->getRedirectTitle()->isExternal() );
		$this->assertTrue( $result->getSectionTitle()->isExternal() );
	}
}
