<?php

namespace CirrusSearch\Elastica;

use CirrusSearch\CirrusTestCase;
use Elastica\Client;
use Elastica\Index;
use Elastica\Request;
use Elastica\Response;

/**
 * @covers \CirrusSearch\Elastica\ReindexRequest
 */
class ReindexRequestTest extends CirrusTestCase {
	public function testAcceptsIndexSourceAndDest() {
		$client = $this->createNoOpMock( Client::class );
		$sourceIndex = new Index( $client, 'source_idx' );
		$destIndex = new Index( $client, 'dest_idx' );

		$req = new ReindexRequest( $sourceIndex, $destIndex );
		$this->assertEquals( [
			'source' => [
				'index' => 'source_idx',
				'size' => 100,
			],
			'dest' => [
				'index' => 'dest_idx',
			],
		], $req->toArray() );
	}

	public function testOneSliceByDefault() {
		$client = $this->createMock( Client::class );
		$sourceIndex = new Index( $client, 'source_idx' );
		$destIndex = new Index( $client, 'dest_idx' );
		$req = new ReindexRequest( $sourceIndex, $destIndex );

		$client->expects( $this->once() )
			->method( 'request' )
			->with( '_reindex', Request::POST, $req->toArray(), [
				'slices' => 1,
				'requests_per_second' => -1,
			] )
			->willReturn( new Response( '{}', 200 ) );

		$this->assertInstanceOf( ReindexResponse::class, $req->reindex() );
	}

	public function testSlicesAreConfigurable() {
		$client = $this->createMock( Client::class );
		$sourceIndex = new Index( $client, 'source_idx' );
		$destIndex = new Index( $client, 'dest_idx' );
		$req = new ReindexRequest( $sourceIndex, $destIndex );
		$req->setSlices( 12 );

		$client->expects( $this->once() )
			->method( 'request' )
			->with( '_reindex', Request::POST, $req->toArray(), [
				'slices' => 12,
				'requests_per_second' => -1,
			] )
			->willReturn( new Response( '{}', 200 ) );

		$this->assertInstanceOf( ReindexResponse::class, $req->reindex() );
	}

	public function setRequestsPerSecondIsConfigurable() {
		$client = $this->createMock( Client::class );
		$sourceIndex = new Index( $client, 'source_idx' );
		$destIndex = new Index( $client, 'dest_idx' );
		$req = new ReindexRequest( $sourceIndex, $destIndex );
		$req->setRequestsPerSecond( 42 );

		$client->expects( $this->once() )
			->method( 'request' )
			->with( '_reindex', Request::POST, $req->toArray(), [
				'slices' => 12,
				'requests_per_second' => 42,
			] )
			->willReturn( new Response( '{}', 200 ) );

		$this->assertInstanceOf( ReindexResponse::class, $req->reindex() );
	}

	public function testReindexTask() {
		$client = $this->createMock( Client::class );
		$sourceIndex = new Index( $client, 'source_idx' );
		$destIndex = new Index( $client, 'dest_idx' );
		$req = new ReindexRequest( $sourceIndex, $destIndex );

		$client->expects( $this->once() )
			->method( 'request' )
			->with( '_reindex', Request::POST, $req->toArray(), [
				'slices' => 1,
				'requests_per_second' => -1,
				'wait_for_completion' => 'false',
			] )
			->willReturn( new Response( '{"task": "qwerty:4321"}', 200 ) );

		$task = $req->reindexTask();
		$this->assertInstanceOf( ReindexTask::class, $task );
		$this->assertEquals( "qwerty:4321", $task->getId() );
	}

	public function testProvideScript() {
		$client = $this->createNoOpMock( Client::class );
		$sourceIndex = new Index( $client, 'source_idx' );
		$destIndex = new Index( $client, 'dest_idx' );
		$req = new ReindexRequest( $sourceIndex, $destIndex );
		$req->setScript( [
			'lang' => 'painless',
			'inline' => 'fofofo;'
		] );

		$this->assertEquals( [
			'source' => [
				'index' => 'source_idx',
				'size' => 100,
			],
			'dest' => [
				'index' => 'dest_idx',
			],
			'script' => [
				'lang' => 'painless',
				'inline' => 'fofofo;',
			]
		], $req->toArray() );
	}

	public function setProvideRemoteInfo() {
		$client = $this->createNoOpMock( Client::class );
		$sourceIndex = new Index( $client, 'source_idx' );
		$destIndex = new Index( $client, 'dest_idx' );
		$req = new ReindexRequest( $sourceIndex, $destIndex );
		$req->setRemoteInfo( [
			'host' => 'http://otherhost:9200',
		] );
		$this->assertEquals( [
			'source' => [
				'index' => 'source_idx',
				'size' => 100,
				'remote' => [
					'host' => 'http://otherhost:9200'
				],
			],
			'dest' => [
				'index' => 'dest_idx',
			],
		], $req->toArray() );
	}

	public function testPerformsRequestAgainstDestinationCluster() {
		$sourceClient = $this->createNoOpMock( Client::class );
		$destClient = $this->createMock( Client::class );

		$destClient->expects( $this->once() )
			->method( 'request' )
			->willReturn( new Response( '{}', 200 ) );
		$sourceIndex = new Index( $sourceClient, 'source_idx' );
		$destIndex = new Index( $destClient, 'dest_idx' );
		$req = new ReindexRequest( $sourceIndex, $destIndex );
		$this->assertInstanceOf( ReindexResponse::class, $req->reindex() );
	}
}
