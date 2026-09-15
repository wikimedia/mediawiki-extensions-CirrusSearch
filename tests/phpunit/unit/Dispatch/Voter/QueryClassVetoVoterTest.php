<?php

namespace CirrusSearch\Dispatch\Voter;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Parser\BasicQueryClassifier;

/**
 * @covers \CirrusSearch\Dispatch\Voter\QueryClassVetoVoter
 * @group CirrusSearch
 */
class QueryClassVetoVoterTest extends CirrusTestCase {

	public static function provideVotes() {
		return [
			'the class the route serves' => [
				[ BasicQueryClassifier::SIMPLE_BAG_OF_WORDS ], 'catapults are fun', RouteVote::Abstain,
			],
			'one of several classes served' => [
				[ BasicQueryClassifier::SIMPLE_PHRASE, BasicQueryClassifier::SIMPLE_BAG_OF_WORDS ],
				'catapults are fun', RouteVote::Abstain,
			],
			'a class the route does not serve' => [
				[ BasicQueryClassifier::SIMPLE_PHRASE ], 'catapults are fun', RouteVote::Veto,
			],
		];
	}

	/**
	 * @dataProvider provideVotes
	 */
	public function testVote( array $classes, string $term, RouteVote $expected ) {
		$query = $this->getNewFTSearchQueryBuilder( new HashSearchConfig( [] ), $term )->build();
		$this->assertSame( $expected, ( new QueryClassVetoVoter( $classes ) )->vote( $query ) );
	}

	/**
	 * A route that serves every kind of query has nothing to check.
	 */
	public function testNoClassesMeansNoVoter() {
		$this->assertNull( QueryClassVetoVoter::build( new HashSearchConfig( [] ), [] ) );
		$this->assertNotNull( QueryClassVetoVoter::build( new HashSearchConfig( [] ),
			[ QueryClassVetoVoter::PARAM_CLASSES => [ BasicQueryClassifier::SIMPLE_PHRASE ] ] ) );
	}
}
