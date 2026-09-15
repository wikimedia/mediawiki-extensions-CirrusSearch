<?php

namespace CirrusSearch\Dispatch\Voter;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\HashSearchConfig;

/**
 * @covers \CirrusSearch\Dispatch\Voter\NamespaceVetoVoter
 * @group CirrusSearch
 */
class NamespaceVetoVoterTest extends CirrusTestCase {

	public static function provideVotes() {
		return [
			'the only namespace served' => [ [ 0 ], [ 0 ], RouteVote::Abstain ],
			'a subset of what is served' => [ [ 0, 1, 2 ], [ 0, 2 ], RouteVote::Abstain ],
			'exactly what is served' => [ [ 0, 1 ], [ 0, 1 ], RouteVote::Abstain ],
			'a namespace not served' => [ [ 0 ], [ 1 ], RouteVote::Veto ],
			'one served and one not' => [ [ 0 ], [ 0, 1 ], RouteVote::Veto ],
			'no namespace at all could be anything' => [ [ 0 ], [], RouteVote::Veto ],
		];
	}

	/**
	 * @dataProvider provideVotes
	 */
	public function testVote( array $served, array $queried, RouteVote $expected ) {
		$query = $this->getNewFTSearchQueryBuilder( new HashSearchConfig( [] ), 'foo' )
			->setInitialNamespaces( $queried )
			->build();
		$this->assertSame( $queried, $query->getNamespaces(), 'query namespaces as the case intends' );
		$this->assertSame( $expected, ( new NamespaceVetoVoter( $served ) )->vote( $query ) );
	}

	/**
	 * A route that serves everything has nothing to check, so there is no voter to ask.
	 */
	public function testNoNamespacesMeansNoVoter() {
		$this->assertNull( NamespaceVetoVoter::build( new HashSearchConfig( [] ), [] ) );
		$this->assertNotNull( NamespaceVetoVoter::build( new HashSearchConfig( [] ),
			[ NamespaceVetoVoter::PARAM_NAMESPACES => [ NS_MAIN ] ] ) );
	}
}
