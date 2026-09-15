<?php

namespace CirrusSearch\Dispatch\Voter;

use CirrusSearch\CirrusConfigNames;
use CirrusSearch\CirrusTestCase;
use CirrusSearch\HashSearchConfig;

/**
 * @covers \CirrusSearch\Dispatch\Voter\QueryLengthCandidateVoter
 * @group CirrusSearch
 */
class QueryLengthCandidateVoterTest extends CirrusTestCase {

	public static function provideVotes() {
		return [
			'below the threshold abstains' => [ 5, 'how do catapults work', RouteVote::Abstain ],
			'at the threshold is a candidate' => [ 4, 'how do catapults work', RouteVote::Candidate ],
			'above the threshold is a candidate' => [ 2, 'how do catapults work', RouteVote::Candidate ],
			'single token' => [ 2, 'catapult', RouteVote::Abstain ],
			'runs of whitespace count once' => [ 3, "how   do \t catapults", RouteVote::Candidate ],
			'surrounding whitespace is not a token' => [ 2, '  catapult  ', RouteVote::Abstain ],
			'empty query' => [ 1, '', RouteVote::Abstain ],
		];
	}

	/**
	 * @dataProvider provideVotes
	 */
	public function testVote( int $threshold, string $queryString, RouteVote $expected ) {
		$config = new HashSearchConfig( [] );
		$query = $this->getNewFTSearchQueryBuilder( $config, $queryString )->build();
		$this->assertSame( $expected, ( new QueryLengthCandidateVoter( $threshold ) )->vote( $query ) );
	}

	/**
	 * The namespace header routes the query, it is not part of what the user asked for.
	 */
	public function testNamespaceHeaderIsNotCounted() {
		$config = new HashSearchConfig( [] );
		$query = $this->getNewFTSearchQueryBuilder( $config, 'help: how do catapults work' )->build();
		$this->assertSame( RouteVote::Abstain, ( new QueryLengthCandidateVoter( 5 ) )->vote( $query ) );
		$this->assertSame( RouteVote::Candidate, ( new QueryLengthCandidateVoter( 4 ) )->vote( $query ) );
	}

	public function testBuildReadsTheSettingItsProfileNames() {
		$config = new HashSearchConfig( [ CirrusConfigNames::SemanticQueryLengthThreshold => 4 ] );
		$voter = QueryLengthCandidateVoter::build( $config, [
			QueryLengthCandidateVoter::PARAM_THRESHOLD_CONFIG => CirrusConfigNames::SemanticQueryLengthThreshold,
		] );
		$this->assertNotNull( $voter );
		$query = $this->getNewFTSearchQueryBuilder( $config, 'how do catapults work' )->build();
		$this->assertSame( RouteVote::Candidate, $voter->vote( $query ) );
	}

	public function testBuildThresholdParamWinsOverTheSetting() {
		$config = new HashSearchConfig( [ CirrusConfigNames::SemanticQueryLengthThreshold => 4 ] );
		$voter = QueryLengthCandidateVoter::build( $config, [
			QueryLengthCandidateVoter::PARAM_THRESHOLD => 99,
			QueryLengthCandidateVoter::PARAM_THRESHOLD_CONFIG => CirrusConfigNames::SemanticQueryLengthThreshold,
		] );
		$this->assertNotNull( $voter );
		$query = $this->getNewFTSearchQueryBuilder( $config, 'how do catapults work' )->build();
		$this->assertSame( RouteVote::Abstain, $voter->vote( $query ) );
	}

	public static function provideNoThreshold() {
		return [
			'no params at all' => [ [] ],
			'a setting the wiki never set' => [
				[ QueryLengthCandidateVoter::PARAM_THRESHOLD_CONFIG => CirrusConfigNames::SemanticQueryLengthThreshold ]
			],
		];
	}

	/**
	 * Without a threshold the voter has nothing to say, so the router must leave it out.
	 * @dataProvider provideNoThreshold
	 */
	public function testBuildWithoutThreshold( array $params ) {
		$this->assertNull( QueryLengthCandidateVoter::build( new HashSearchConfig( [] ), $params ) );
	}
}
