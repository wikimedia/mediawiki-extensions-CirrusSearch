<?php

declare( strict_types = 1 );
namespace CirrusSearch\Elastica;

use CirrusSearch\CirrusTestCase;
use Elastica\Client;
use Elastica\Connection;
use Elastica\Query;
use Elastica\Query\MatchAll;
use Elastica\Request;
use Elastica\Response;
use Elastica\Search;
use Elastica\Transport\AbstractTransport;
use InvalidArgumentException;
use RuntimeException;

/**
 * @covers \CirrusSearch\Elastica\SearchAfter
 */
class SearchAfterTest extends CirrusTestCase {
	public static $lastRequest;

	private function makeClient( array $responses ) {
		$transport = [
			"transport" => new class( $responses ) extends AbstractTransport {
				private $responses;

				public function __construct( array $responses ) {
					$this->responses = $responses;
				}

				public function exec( Request $request, array $params ): Response {
					SearchAfterTest::$lastRequest = $request;
					if ( !count( $this->responses ) ) {
						throw new \Exception( '...' );
					}
					return array_shift( $this->responses );
				}

				public function toArray() {
					return [];
				}
			},
		];

		$client = new Client();
		$client->setConnections( [ new Connection( $transport ) ] );
		return $client;
	}

	private function makeResponse( array $hits ): Response {
		$response = [ 'hits' => [ 'hits' => $hits ] ];
		return new Response( $response, 200 );
	}

	private function lastRequestData() {
		$data = self::$lastRequest->getParam( 'data' );
		// blank to ensure future reads don't get stale data
		self::$lastRequest = null;
		return $data;
	}

	public function testRequiresSort() {
		$client = $this->makeClient( [] );

		// should not throw
		$q = ( new Query( new MatchAll() ) )
			->setSort( [ [ '_id' => 'asc' ] ] );
		new SearchAfter( ( new Search( $client ) )->setQuery( $q ) );

		$q = new Query( new MatchAll() );
		$this->expectException( InvalidArgumentException::class,
			"Must throw when query doesn't provide a sort" );
		new SearchAfter( ( new Search( $client ) )->setQuery( $q ) );
	}

	public function testProvidesLastHitSort() {
		$client = $this->makeClient( [
			$this->makeResponse( [
				[ '_id' => 42, 'sort' => [ 42 ] ],
				[ '_id' => 43, 'sort' => [ 43 ] ],
			] ),
			$this->makeResponse( [
				[ '_id' => 58, 'sort' => [ 58 ] ],
				[ '_id' => 59, 'sort' => [ 59 ] ],
			] ),
			$this->makeResponse( [] ),
		] );

		$search = new Search( $client );
		$q = ( new Query( new MatchAll() ) )
			->setSort( [ [ '_id' => 'asc' ] ] );
		$search->setQuery( $q );
		$it = new SearchAfter( $search );

		$it->rewind();
		$this->assertTrue( $it->valid() );
		$this->assertSame( 0, $it->key() );
		// First query must not provide search_after
		$lastRequest = $this->lastRequestData();
		$this->assertArrayNotHasKey( 'search_after', $lastRequest );

		// Followups must provide search_after
		$it->next();
		$this->assertTrue( $it->valid() );
		$this->assertSame( 1, $it->key() );
		$lastRequest = $this->lastRequestData();
		$this->assertArrayHasKey( 'search_after', $lastRequest );
		$this->assertEquals( [ 43 ], $lastRequest['search_after'] );

		$it->next();
		$lastRequest = $this->lastRequestData();
		$this->assertFalse( $it->valid() );
		$this->assertArrayHasKey( 'search_after', $lastRequest );
		$this->assertEquals( [ 59 ], $lastRequest['search_after'] );
	}

	public function testIteration() {
		$client = $this->makeClient( [
			$this->makeResponse( [
				[ '_id' => 42, 'sort' => [ 42 ] ],
			] ),
			$this->makeResponse( [
				[ '_id' => 43, 'sort' => [ 43 ] ],
			] ),
			$this->makeResponse( [] ),

			// repeat after rewind
			$this->makeResponse( [
				[ '_id' => 42, 'sort' => [ 42 ] ],
			] ),
			$this->makeResponse( [
				[ '_id' => 43, 'sort' => [ 43 ] ],
			] ),
			$this->makeResponse( [] ),
		] );

		$q = ( new Query( new MatchAll() ) )
			->setSort( [ [ '_id' => 'asc' ] ] );
		$search = ( new Search( $client ) )
			->setQuery( $q );
		$it = new SearchAfter( $search );

		$seen = 0;
		foreach ( $it as $resultSet ) {
			$seen++;
		}
		$this->assertEquals( 2, $seen );

		// Verify rewind works
		$seen = 0;
		foreach ( $it as $resultSet ) {
			$seen++;
		}
		$this->assertEquals( 2, $seen );
	}

	public function testNotValidPriorToRewind() {
		$client = $this->makeClient( [] );
		$q = ( new Query( new MatchAll() ) )
			->setSort( [ [ '_id' => 'asc' ] ] );
		$search = ( new Search( $client ) )
			->setQuery( $q );
		$it = new SearchAfter( $search );
		$this->assertFalse( $it->valid() );

		$this->expectException( RuntimeException::class,
			'current() when iterator is not valid must throw' );
		$it->current();
	}
}
