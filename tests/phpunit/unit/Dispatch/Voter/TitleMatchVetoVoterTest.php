<?php

namespace CirrusSearch\Dispatch\Voter;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Test\StaticTitleMatchLookup;

/**
 * @covers \CirrusSearch\Dispatch\Voter\TitleMatchVetoVoter
 * @group CirrusSearch
 */
class TitleMatchVetoVoterTest extends CirrusTestCase {

	public static function provideVotes() {
		return [
			'a query naming a page stays off the route' => [ true, RouteVote::Veto ],
			'a query naming no page is left to the other voters' => [ false, RouteVote::Abstain ],
			'a lookup that can\'t answer keeps the query off the route' => [ null, RouteVote::Veto ],
		];
	}

	/**
	 * @dataProvider provideVotes
	 */
	public function testVote( ?bool $matches, RouteVote $expected ) {
		$query = $this->getNewFTSearchQueryBuilder( new HashSearchConfig( [] ), 'catapult' )->build();
		$voter = new TitleMatchVetoVoter( new StaticTitleMatchLookup( $matches ) );
		$this->assertSame( $expected, $voter->vote( $query ) );
	}
}
