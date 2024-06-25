<?php

namespace CirrusSearch;

/**
 * @covers \CirrusSearch\Version
 */
class VersionTest extends CirrusIntegrationTestCase {
	public function testHappyPath() {
		$response = $this->returnValue( new \Elastica\Response( json_encode( [
			'name' => 'testhost',
			'cluster_name' => 'phpunit-search',
			'version' => [
				'number' => '3.2.1',
			],
		] ), 200 ) );
		$conn = $this->mockConnection( $response );
		$version = new Version( $conn );
		$status = $version->get();
		$this->assertStatusGood( $status );
		$this->assertEquals( '3.2.1', $status->getValue() );
	}

	public function testFailure() {
		$response = $this->throwException(
			new \Elastica\Exception\Connection\HttpException( CURLE_COULDNT_CONNECT )
		);
		$conn = $this->mockConnection( $response );
		$version = new Version( $conn );
		$status = $version->get();
		$this->assertFalse( $status->isOK() );
	}

	public function mockConnection( $responseAction ) {
		$client = $this->createMock( \Elastica\Client::class );
		$client->method( 'request' )
			->will( $responseAction );

		$config = $this->newHashSearchConfig(
			[ 'CirrusSearchClientSideSearchTimeout' => [
				'default' => 5
			] ]
		);
		$conn = $this->createMock( Connection::class );
		$conn->method( 'getClient' )
			->willReturn( $client );
		$conn->method( 'getClusterName' )
			->willReturn( 'default' );
		$conn->expects( ( $this->any() ) )
			->method( 'getConfig' )
			->willReturn( $config );
		return $conn;
	}
}
