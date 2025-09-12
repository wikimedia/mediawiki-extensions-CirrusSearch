<?php

namespace CirrusSearch\MetaStore;

use CirrusSearch\CirrusIntegrationTestCase;
use CirrusSearch\Connection;
use CirrusSearch\HashSearchConfig;
use Elastica\Query;
use Elastica\Response;
use Elastica\ResultSet;
use MediaWiki\WikiMap\WikiMap;

/**
 * Mostly stupid happy path tests. :(
 *
 * @covers \CirrusSearch\MetaStore\MetaVersionStore
 */
class MetaVersionStoreTest extends CirrusIntegrationTestCase {
	public function testBuildDocument() {
		[ $conn, $type ] = $this->mockConnection();
		$doc = MetaVersionStore::buildDocument( $conn, WikiMap::getCurrentWikiId(), 'content' );
		$this->assertEquals( MetaVersionStore::METASTORE_TYPE, $doc->get( 'type' ) );
	}

	public function testUpdate() {
		[ $conn, $type ] = $this->mockConnection();
		$store = new MetaVersionStore( $type, $conn );
		$doc = null;
		$type->expects( $this->once() )
			->method( 'addDocuments' )
			->willReturnCallback( static function ( $arg ) use ( &$doc ) {
				$doc = $arg[0];
			} );

		$store->update( 'unittest', 'general' );
		$this->assertInstanceOf( \Elastica\Document::class, $doc );
	}

	public function testDelete() {
		[ $conn, $type ] = $this->mockConnection();
		$store = new MetaVersionStore( $type, $conn );
		$id = null;
		$type->expects( $this->once() )
			->method( 'deleteById' )
			->willReturnCallback( function ( string $id, array $args ) {
				$this->assertEquals( "version-unittest_titlesuggest_alt_1", $id );
				$this->assertEquals( [], $args );
				return new Response( 200, [] );
			} );

		$store->delete( 'unittest', Connection::TITLE_SUGGEST_INDEX_SUFFIX, true, 1 );
	}

	public function testUpdateAll() {
		[ $conn, $type ] = $this->mockConnection();
		$store = new MetaVersionStore( $type, $conn );
		$type->expects( $this->once() )
			->method( 'addDocuments' )
			->willReturnCallback( function ( $docs ) {
				$this->assertCount( 3, $docs );
			} );
		$store->updateAll( 'unittest' );
	}

	public function testBuildIndexProperties() {
		[ $conn, $type ] = $this->mockConnection();
		$store = new MetaVersionStore( $type, $conn );
		$properties = $store->buildIndexProperties();
		// TODO: Would be nice to have some sort of check that these
		// are valid to elasticsearch. But thats more on integration
		// testing again
		$this->assertIsArray( $properties );
	}

	public function testFind() {
		$getBehavior = function ( $type ) {
			$type->expects( $this->once() )
				->method( 'getDocument' )
				->with( 'version-unittest_content' );
		};
		[ $conn, $type ] = $this->mockConnection( $getBehavior );
		$store = new MetaVersionStore( $type, $conn );
		$store->find( 'unittest', 'content' );
	}

	public function testFindAll() {
		[ $conn, $index ] = $this->mockConnection();
		$store = new MetaVersionStore( $index, $conn );
		$search = null;
		$index->method( 'search' )
			->willReturnCallback( static function ( $passed ) use ( &$search ) {
				$search = $passed;
				return new ResultSet( new Response( [] ), new Query(), [] );
			} );
		// What can we really test? Feels more like integration
		// testing that needs the elasticsearch cluster. Or we
		// could VCR some results but they will change regularly
		$store->findAll();
		$this->assertNotNull( $search );

		$search = null;
		$store->findAll( 'unittest' );
		$this->assertNotNull( $search );
	}

	private function mockConnection( $getBehavior = null ) {
		$config = new HashSearchConfig( [
			'CirrusSearchReplicaGroup' => 'default',
			'CirrusSearchClusters' => [ 'default' => [ 'localhost' ] ],
			'CirrusSearchDefaultCluster' => 'default',
			'CirrusSearchNamespaceMappings' => [],
			'CirrusSearchShardCount' => [
				'content' => 1,
				'general' => 1,
				'archive' => 1,
				'titlesuggest' => 1,
			],
			'CirrusSearchEnableArchive' => true,
		] );
		$conn = $this->getMockBuilder( Connection::class )
			->setConstructorArgs( [ $config ] )
			// call real connection on unmocked methods
			->onlyMethods( [ 'getIndex' ] )
			->getMock();

		$index = $this->createMock( \Elastica\Index::class );
		$conn->method( 'getIndex' )
			->with( MetaStoreIndex::INDEX_NAME )
			->willReturn( $index );

		$index->method( 'exists' )
			->willReturn( true );

		if ( $getBehavior !== null ) {
			$getBehavior( $index );
		}

		return [ $conn, $index ];
	}
}
