<?php

namespace CirrusSearch\MetaStore;

use CirrusSearch\CirrusIntegrationTestCase;
use Elastica\Document;

/**
 * @covers \CirrusSearch\MetaStore\MetaSaneitizeJobStore
 */
class MetaSaneitizeJobStoreTest extends CirrusIntegrationTestCase {
	public function testCreate() {
		$index = $this->mockIndex();
		$index->expects( $this->once() )
			->method( 'addDocuments' );
		$store = new MetaSaneitizeJobStore( $index );
		$doc = $store->create( 'foo', 2018 );
		$this->assertEquals( MetaSaneitizeJobStore::METASTORE_TYPE, $doc->get( 'type' ) );
	}

	public function testGetMissing() {
		$index = $this->mockIndex(
			$this->throwException( new \Elastica\Exception\NotFoundException() )
		);

		$store = new MetaSaneitizeJobStore( $index );
		$this->assertNull( $store->get( 'foo' ) );
	}

	public function testGet() {
		$index = $this->mockIndex( $this->returnValue( new Document( 'FOUND' ) ) );
		$store = new MetaSaneitizeJobStore( $index );
		$this->assertEquals( new Document( 'FOUND' ), $store->get( 'foo' ) );
	}

	public function mockIndex( $getBehavior = null ) {
		$index = $this->getMockBuilder( \Elastica\Index::class )
			->disableOriginalConstructor()
			->getMock();

		if ( $getBehavior !== null ) {
			$index->expects( $this->once() )
				->method( 'getDocument' )
				->will( $getBehavior );
		}

		return $index;
	}
}
