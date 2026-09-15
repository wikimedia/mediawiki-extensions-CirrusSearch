<?php

namespace CirrusSearch\Dispatch\Voter;

use CirrusSearch\CirrusDebugOptions;
use CirrusSearch\CirrusTestCase;
use CirrusSearch\HashSearchConfig;

/**
 * @covers \CirrusSearch\Dispatch\Voter\SemanticDebugOptionForceVoter
 * @group CirrusSearch
 */
class SemanticDebugOptionForceVoterTest extends CirrusTestCase {

	private function vote( CirrusDebugOptions $options ): RouteVote {
		$query = $this->getNewFTSearchQueryBuilder( new HashSearchConfig( [] ), 'foo' )
			->setDebugOptions( $options )
			->build();
		return ( new SemanticDebugOptionForceVoter() )->vote( $query );
	}

	/**
	 * Force rather than candidate: a request that already said what it wants must not be
	 * overruled by a voter that runs after it.
	 */
	public function testTheOptionForcesTheRoute() {
		$this->assertSame( RouteVote::Force,
			$this->vote( CirrusDebugOptions::forSemanticSearchUnitTests() ) );
	}

	public function testWithoutTheOptionItSaysNothing() {
		$this->assertSame( RouteVote::Abstain,
			$this->vote( CirrusDebugOptions::defaultOptions() ) );
	}
}
