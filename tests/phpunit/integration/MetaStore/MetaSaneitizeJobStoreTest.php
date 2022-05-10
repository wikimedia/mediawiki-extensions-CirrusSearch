<?php

namespace CirrusSearch\MetaStore;

use CirrusSearch\CirrusIntegrationTestCase;

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
		$index = $this->mockIndex( $this->returnValue( 'FOUND' ) );
		$store = new MetaSaneitizeJobStore( $index );
		$this->assertEquals( 'FOUND', $store->get( 'foo' ) );
	}

	public function mockIndex( $getBehavior = null ) {
		$index = $this->getMockBuilder( \Elastica\Index::class )
			->disableOriginalConstructor()
			->getMock();

		if ( $getBehavior !== null ) {
			// TODO: remove references to type (T308044)
			$type = $this->getMockBuilder( \Elastica\Type::class )
				->disableOriginalConstructor()
				->getMock();

			$type->expects( $this->once() )
				->method( 'getDocument' )
				->will( $getBehavior );
			$index->method( 'getType' )->willReturn( $type );
		}

		return $index;
	}
}
