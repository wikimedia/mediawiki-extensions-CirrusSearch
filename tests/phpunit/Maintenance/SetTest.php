<?php

namespace CirrusSearch\Maintenance;

/**
 * @covers \CirrusSearch\Maintenance\Set
 */
class SetTest extends \PHPUnit\Framework\TestCase {
	public function testAdd() {
		$set = new Set();
		$this->assertEquals( 0, count( $set ) );
		$this->assertFalse( $set->contains( 'foo' ) );
		$set->add( 'foo' );
		$this->assertEquals( [ 'foo' ], $set->values() );
		$this->assertEquals( 1, count( $set ) );
		$this->assertTrue( $set->contains( 'foo' ) );
		$set->add( 'foo' );
		$this->assertEquals( [ 'foo' ], $set->values() );
		$this->assertEquals( 1, count( $set ) );
		$this->assertTrue( $set->contains( 'foo' ) );
	}

	public function testAddAll() {
		$set = new Set();
		$set->addAll( [ 1, 2, 3 ] );
		$this->assertEquals( [ 1, 2, 3 ], $set->values() );
		$this->assertEquals( 3, count( $set ) );
		$this->assertFalse( $set->contains( 0 ) );
		$this->assertTrue( $set->contains( 1 ) );
		$this->assertTrue( $set->contains( 2 ) );
		$this->assertTrue( $set->contains( 3 ) );
		$this->assertFalse( $set->contains( 4 ) );
	}

	public function testUnion() {
		$a = new Set();
		$a->addAll( [ 1, 2, 3 ] );
		$b = new Set();
		$b->addAll( [ 3, 4, 5 ] );

		$this->assertEquals( 3, count( $a ) );
		$a->union( $b );
		$this->assertEquals( 5, count( $a ) );
		$this->assertEquals( 3, count( $b ) );
	}

}
