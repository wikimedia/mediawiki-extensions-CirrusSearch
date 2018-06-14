<?php

namespace CirrusSearch;

use PHPUnit\Framework\TestCase;

/**
 * @covers \CirrusSearch\CirrusDebugOptions
 */
class CirrusDebugOptionsTest extends TestCase {

	public function testEmptyOptions() {
		$request = new \FauxRequest();
		$debugOptions = CirrusDebugOptions::fromRequest( $request );
		$this->assertNone( $debugOptions );
	}

	public function testFullOptions() {
		$request = new \FauxRequest( [
			'cirrusMLRModel' => 'my_model',
			'cirrusSuppressSuggest' => '',
			'cirrusCompletionVariant' => [ 'foo', 'bar' ],
			'cirrusDumpQuery' => '',
			'cirrusDumpResult' => '',
			'cirrusExplain' => 'pretty'
		] );
		$debugOptions = CirrusDebugOptions::fromRequest( $request );
		$this->assertEquals( 'my_model', $debugOptions->getCirrusMLRModel() );
		$this->assertEquals( 'pretty', $debugOptions->getCirrusExplain() );
		$this->assertTrue( $debugOptions->isCirrusSuppressSuggest() );
		$this->assertTrue( $debugOptions->isCirrusDumpQuery() );
		$this->assertTrue( $debugOptions->isCirrusDumpResult() );
		$this->assertEquals( [ 'foo', 'bar' ], $debugOptions->getCirrusCompletionVariant() );
		$this->assertTrue( $debugOptions->isReturnRaw() );
		$this->assertTrue( $debugOptions->isDumpAndDie() );
	}

	public function testNone() {
		$this->assertNone( CirrusDebugOptions::defaultOptions() );
	}

	public function testUnitTests() {
		$debugOptions = CirrusDebugOptions::forDumpingQueriesInUnitTests();
		$this->assertNull( $debugOptions->getCirrusMLRModel() );
		$this->assertNull( $debugOptions->getCirrusExplain() );
		$this->assertFalse( $debugOptions->isCirrusSuppressSuggest() );
		$this->assertTrue( $debugOptions->isCirrusDumpQuery() );
		$this->assertFalse( $debugOptions->isCirrusDumpResult() );
		$this->assertNull( $debugOptions->getCirrusCompletionVariant() );
		$this->assertTrue( $debugOptions->isReturnRaw() );
		$this->assertFalse( $debugOptions->isDumpAndDie() );
	}

	private function assertNone( CirrusDebugOptions $debugOptions ) {
		$this->assertNull( $debugOptions->getCirrusMLRModel() );
		$this->assertNull( $debugOptions->getCirrusExplain() );
		$this->assertFalse( $debugOptions->isCirrusSuppressSuggest() );
		$this->assertFalse( $debugOptions->isCirrusDumpQuery() );
		$this->assertFalse( $debugOptions->isCirrusDumpResult() );
		$this->assertNull( $debugOptions->getCirrusCompletionVariant() );
		$this->assertFalse( $debugOptions->isReturnRaw() );
		$this->assertFalse( $debugOptions->isDumpAndDie() );
	}
}
