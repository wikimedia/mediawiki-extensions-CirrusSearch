<?php

namespace CirrusSearch\Search;

class ResultTest extends \MediaWikiTestCase {
	public function testInterwikiResults() {
		$this->setMwGlobals( array(
			'wgCirrusSearchWikiToNameMap' => array(
				'es' => 'eswiki',
			),
		) );

		$elasticaResultSet = $this->getMockBuilder( \Elastica\ResultSet::class )
			->disableOriginalConstructor()
			->getMock();

		$elasticaResult = new \Elastica\Result( array(
			'_index' => 'eswiki_content_123456',
			'_source' => array(
				'namespace' => NS_MAIN,
				'title' => 'Main Page',
				'redirect' => array(
					array(
						'title' => 'Main',
						'namespace' => NS_MAIN,
					),
				),
			),
			'highlight' => array(
				'redirect.title' => array( 'Main' ),
				'heading' => array( '...' ),
			),
		) );
		$result = new Result( $elasticaResultSet, $elasticaResult, 'es' );

		$this->assertTrue( $result->getTitle()->isExternal() );
		$this->assertTrue( $result->getRedirectTitle()->isExternal() );
		$this->assertTrue( $result->getSectionTitle()->isExternal() );
	}
}
