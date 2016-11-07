<?php

namespace CirrusSearch;

use CirrusSearch;
use CirrusSearch\Test\HashSearchConfig;
use FauxRequest;
use RequestContext;
use Title;

class InterwikiSearcherTest extends \MediaWikiTestCase {
	public function loadTestProvider() {
		return [
			'when load test is null normal interwiki search applies' => [
				true,
				'Special:Search',
				null,
			],
			'when load test is 0 no interwiki search is performed' => [
				false,
				'Special:Search',
				0
			],
			'when load test is >= 1 interwiki search is performed;' => [
				true,
				'Special:Search',
				1
			],
			'load testing only applies to Special:Search' => [
				false,
				'Special:AbuseFilter',
				1
			],
			'load testing appropriatly handles aliases of Special:Search' => [
				false,
				'Служебная:Поиск',
				1
			],
		];
	}

	/**
	 * @dataProvider loadTestProvider
	 */
	public function testLoadTest( $expectInterwiki, $titleString, $loadTest ) {
		// Maybe much of this should be extracted into some sort of MockConnection
		// or some such...
		$calls = 0;
		$client = $this->getMockBuilder( \Elastica\Client::class )
			->setMethods( ['request'] )
			->getMock();
		$client->expects( $this->any() )
			->method( 'request' )
			->will( $this->returnCallback( function ( $path, $method, $data, $options ) use ( &$calls ) {
				$newCalls = substr_count($data, "\n");
				if ( $newCalls > 1 ) {
					// bulk calls are two at a time
					$newCalls /= 2;
				}
				$fixture = ['total' => 0, 'hits' => []];
				$calls += $newCalls;
				return new \Elastica\Response( json_encode( [
					'responses' => array_fill(0, $newCalls, ['total' => 0, 'hits' => []])
				] ), 200);
			} ) );

		$this->setMwGlobals( [
			'wgCirrusSearchInterwikiSources' => [
				'foo' => 'foowiki',
			 ],
			'wgCirrusSearchInterwikiLoadTest' => $loadTest,
		] );

		$context = RequestContext::getMain();
		$context->setTitle( Title::newFromText( $titleString ) );
		$engine = new CirrusSearch();
		$connection = $engine->getConnection();

		$reflProp = new \ReflectionProperty( $connection, 'client' );
		$reflProp->setAccessible( true );
		$reflProp->setValue( $connection, $client );

		$query = $engine->searchText( 'some example' );
		if ( $expectInterwiki ) {
			$this->assertGreaterThan( 1, $calls );
		} else {
			$this->assertEquals( 1, $calls );
		}
	}
}
