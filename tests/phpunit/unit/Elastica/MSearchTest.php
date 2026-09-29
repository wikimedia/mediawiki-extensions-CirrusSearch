<?php

namespace CirrusSearch\Elastica;

use Elastica\Client;
use Elastica\Response;
use Elastica\Search;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CirrusSearch\Elastica\MSearch
 */
class MSearchTest extends TestCase {
	public function testIgnoreUnavailable(): void {
		$client = $this->createMock( Client::class );
		$client->expects( $this->once() )->method( 'request' )
			->willReturnCallback(
				function ( string $path, string $method, string $data, array $options, string $contentType ): Response {
					$this->assertEquals( '_msearch', $path );
					$this->assertEquals( 'POST', $method );
					$expectedQuery = <<<TEXT
{"ignore_unavailable":true}
{"query":{"match_all":{}}}
{}
{"query":{"match_all":{}}}

TEXT;

					$this->assertEquals( $expectedQuery, $data );
					$this->assertEquals( [], $options );
					$this->assertEquals( 'application/x-ndjson', $contentType );
					return new Response( [], 200 );
				}
			);
		$msearch = new MSearch( $client );
		$search = new Search( $this->createMock( Client::class ) );
		$search->setOption( Search::OPTION_SEARCH_IGNORE_UNAVAILABLE, true );
		$msearch->addSearch( $search );
		$search = new Search( $this->createMock( Client::class ) );
		$msearch->addSearch( $search );
		$msearch->search();
	}

}
