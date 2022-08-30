<?php

namespace CirrusSearch\Elastica;

use CirrusSearch\CirrusTestCase;
use Elastica\Client;
use Elastica\Connection;
use Elastica\Document;
use Elastica\Request;
use Elastica\Response;
use Elastica\Transport\AbstractTransport;

/**
 * @covers \CirrusSearch\Elastica\ES6CompatTransportWrapper
 */
class ES6CompatTransportWrapperTest extends CirrusTestCase {
	public static $lastRequest;

	public function test() {
		$transport = [
			"transport" => [
				"type" => ES6CompatTransportWrapper::class,
				"wrapped_transport" => new class() extends AbstractTransport {
					public function exec( Request $request, array $params ): Response {
						ES6CompatTransportWrapperTest::$lastRequest = $request;
						return new Response( "hello", 200 );
					}

					public function toArray() {
						return [];
					}
				}
			]
		];

		$client = new Client();
		$client->setConnections( [ new Connection( $transport ) ] );
		$client->getIndex( "test" )->addDocuments( [
			new Document( "1", [ "field" => "data1" ] ),
			new Document( "2", [ "field" => "data2" ] ),
		] );

		$expectedBulk = json_encode( [ "index" => [ "_id" => "1", "_index" => "test", "_type" => "_doc" ] ] ) . "\n";
		$expectedBulk .= json_encode( [ "field" => "data1" ] ) . "\n";
		$expectedBulk .= json_encode( [ "index" => [ "_id" => "2", "_index" => "test", "_type" => "_doc" ] ] ) . "\n";
		$expectedBulk .= json_encode( [ "field" => "data2" ] ) . "\n";
		$this->assertSame( $expectedBulk, static::$lastRequest->getData() );

		$client->getIndex( "test" )->deleteDocuments( [
			new Document( "1", [ "field" => "data1" ] ),
			new Document( "2", [ "field" => "data2" ] ),
		] );
		$expectedBulk = json_encode( [ "delete" => [ "_id" => "1", "_index" => "test", "_type" => "_doc" ] ] ) . "\n";
		$expectedBulk .= json_encode( [ "delete" => [ "_id" => "2", "_index" => "test", "_type" => "_doc" ] ] ) . "\n";
		$this->assertSame( $expectedBulk, static::$lastRequest->getData() );

		$client->getIndex( 'test' )->search( [ "query" => "test" ] );
		$this->assertInstanceOf( Request::class, static::$lastRequest );
	}
}
