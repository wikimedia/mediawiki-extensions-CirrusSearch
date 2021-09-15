<?php

namespace CirrusSearch;

use MediaWiki\MediaWikiServices;
use MediaWikiUnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @group CirrusSearch
 * @covers \CirrusSearch\CirrusSearchServices
 */
class CirrusSearchServicesTest extends MediaWikiUnitTestCase {

	public function testAll() {
		/** @var CirrusSearch|MockObject $cirrusSearch */
		$cirrusSearch = $this->createNoOpMock( CirrusSearch::class );
		/** @var MediaWikiServices|MockObject $services */
		$services = $this->createNoOpMock( MediaWikiServices::class, [ 'get' ] );
		$services->method( 'get' )->with( 'CirrusSearch' )->willReturn( $cirrusSearch );

		$cirrusSearchServices = new CirrusSearchServices( $services );
		$this->assertSame( $cirrusSearch, $cirrusSearchServices->getCirrusSearch() );

		$cirrusSearchServices = CirrusSearchServices::wrap( $services );
		$this->assertSame( $cirrusSearch, $cirrusSearchServices->getCirrusSearch() );
	}

}
