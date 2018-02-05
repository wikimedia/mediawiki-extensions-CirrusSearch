<?php

namespace CirrusSearch\Profile;

use CirrusSearch\CirrusTestCase;

/**
 * @group CirrusSearch
 * @covers \CirrusSearch\Profile\UriParamSearchProfileOverride
 */
class UriParamSearchProfileOverrideTest extends CirrusTestCase {

	public function testNormalUseCase() {
		$request = new \FauxRequest( [ 'paramOverride' => 'overridden' ] );
		$override = new UriParamSearchProfileOverride( $request, 'paramOverride' );
		$this->assertEquals( SearchProfileOverride::URI_PARAM_PRIO, $override->priority() );
		$this->assertEquals( 'overridden', $override->getOverriddenName() );
	}

	public function testWithoutUriParam() {
		$request = new \FauxRequest( [ 'paramOverride' => 'overridden' ] );
		$override = new UriParamSearchProfileOverride( $request, 'paramOverride2' );
		$this->assertNull( $override->getOverriddenName() );
	}

	public function testCustomPrio() {
		$request = new \FauxRequest( [ 'paramOverride' => 'overridden' ] );
		$override = new UriParamSearchProfileOverride( $request, 'paramOverride2', 123 );
		$this->assertEquals( 123, $override->priority() );
	}
}
