<?php

namespace CirrusSearch\Dispatch;

use CirrusSearch\CirrusTestCase;
use CirrusSearch\Dispatch\Voter\RouteVote;
use CirrusSearch\Dispatch\Voter\RouteVoter;
use CirrusSearch\HashSearchConfig;
use CirrusSearch\Profile\SearchProfileException;
use CirrusSearch\Search\SearchQuery;
use CirrusSearch\SearchConfig;

/**
 * @covers \CirrusSearch\Dispatch\VotedSearchQueryRoute
 * @covers \CirrusSearch\Dispatch\RouteDecision
 */
class VotedSearchQueryRouteTest extends CirrusTestCase {

	private function voter( RouteVote $vote ): RouteVoter {
		return new class( $vote ) implements RouteVoter {
			public int $calls = 0;
			private RouteVote $vote;

			public function __construct( RouteVote $vote ) {
				$this->vote = $vote;
			}

			public static function build( SearchConfig $config, array $params ): ?RouteVoter {
				return null;
			}

			public function vote( SearchQuery $query ): RouteVote {
				$this->calls++;
				return $this->vote;
			}
		};
	}

	private function route( array $voters, float $score = 0.5 ): VotedSearchQueryRoute {
		return new VotedSearchQueryRoute( 'unit_test', SearchQuery::SEARCH_TEXT, 'ctx', $score, $voters );
	}

	private function query(): SearchQuery {
		return $this->getNewFTSearchQueryBuilder( new HashSearchConfig( [] ), 'foo' )->build();
	}

	public static function provideAggregation() {
		return [
			'a candidate takes the query' => [
				[ 'a' => RouteVote::Candidate ], true, RouteDecision::REASON_CANDIDATE,
			],
			'every abstention leaves it' => [
				[ 'a' => RouteVote::Abstain, 'b' => RouteVote::Abstain ], false,
				RouteDecision::REASON_NO_CANDIDATE,
			],
			'no voters at all leaves it' => [
				[], false, RouteDecision::REASON_NO_CANDIDATE,
			],
			'a veto beats a candidate' => [
				[ 'a' => RouteVote::Candidate, 'b' => RouteVote::Veto ], false,
				RouteDecision::REASON_VETOED,
			],
			'a force beats a later veto' => [
				[ 'a' => RouteVote::Force, 'b' => RouteVote::Veto ], true,
				RouteDecision::REASON_FORCED,
			],
			'a veto before a force still wins' => [
				[ 'a' => RouteVote::Veto, 'b' => RouteVote::Force ], false,
				RouteDecision::REASON_VETOED,
			],
		];
	}

	/**
	 * @dataProvider provideAggregation
	 */
	public function testAggregation( array $votes, bool $accepted, string $reason ) {
		$voters = array_map( fn ( RouteVote $v ) => $this->voter( $v ), $votes );
		$decision = $this->route( $voters )->decide( $this->query() );

		$this->assertSame( $accepted, $decision->isAccepted() );
		$this->assertSame( $reason, $decision->getReason() );
		$this->assertSame( $accepted ? 0.5 : 0.0, $decision->getScore() );
	}

	/**
	 * Nothing a later voter says can change a veto or a force, and a voter may talk to the
	 * search backend, so the pass has to stop rather than merely ignore the rest.
	 */
	public function testVetoAndForceStopThePass() {
		foreach ( [ RouteVote::Veto, RouteVote::Force ] as $decisive ) {
			$skipped = $this->voter( RouteVote::Candidate );
			$route = $this->route( [ 'first' => $this->voter( $decisive ), 'second' => $skipped ] );

			$decision = $route->decide( $this->query() );

			$this->assertSame( 0, $skipped->calls, "{$decisive->value} must end the pass" );
			$this->assertSame( [ 'first' => $decisive ], $decision->getVotes() );
		}
	}

	public function testDecisionReportsEveryVoteAsked() {
		$route = $this->route( [
			'abstained' => $this->voter( RouteVote::Abstain ),
			'proposed' => $this->voter( RouteVote::Candidate ),
		] );

		$this->assertSame( [
			'accepted' => true,
			'reason' => RouteDecision::REASON_CANDIDATE,
			'context' => 'ctx',
			'score' => 0.5,
			'votes' => [ 'abstained' => 'abstain', 'proposed' => 'candidate' ],
		], $route->decide( $this->query() )->toArray() );
	}

	public static function provideInvalidEntries() {
		return [
			'no context' => [ [ 'score' => 1.0 ], 'missing \'context\'' ],
			'no score' => [ [ 'context' => 'ctx' ], 'missing \'score\'' ],
			'score above one' => [ [ 'context' => 'ctx', 'score' => 1.5 ], 'at most 1' ],
			'score of zero' => [ [ 'context' => 'ctx', 'score' => 0 ], 'greater than 0' ],
			'voter without a class' => [
				[ 'context' => 'ctx', 'score' => 1.0, 'voters' => [ 'v' => [] ] ],
				"missing 'class'",
			],
			'unknown voter class' => [
				[ 'context' => 'ctx', 'score' => 1.0, 'voters' => [ 'v' => [ 'class' => 'NotAClass' ] ] ],
				'unknown class',
			],
			'voter that is not a voter' => [
				[ 'context' => 'ctx', 'score' => 1.0, 'voters' => [ 'v' => [ 'class' => self::class ] ] ],
				'must implement',
			],
		];
	}

	/**
	 * @dataProvider provideInvalidEntries
	 */
	public function testInvalidProfileEntry( array $entry, string $expected ) {
		$this->expectException( SearchProfileException::class );
		$this->expectExceptionMessageMatches( '/' . preg_quote( $expected, '/' ) . '/' );
		VotedSearchQueryRoute::fromProfileEntry( new HashSearchConfig( [] ),
			SearchQuery::SEARCH_TEXT, 'unit_test', $entry );
	}

	/**
	 * An integer score would slip past the dispatch service's check for two routes claiming
	 * the max, because 1 === 1.0 is false in PHP.
	 */
	public function testIntegerScoreBecomesFloat() {
		$route = VotedSearchQueryRoute::fromProfileEntry( new HashSearchConfig( [] ),
			SearchQuery::SEARCH_TEXT, 'unit_test',
			[ 'context' => 'ctx', 'score' => 1, 'voters' => [
				'all' => [ 'class' => \CirrusSearch\Dispatch\Voter\AllQueriesCandidateVoter::class ],
			] ] );

		$this->assertSame( 1.0, $route->decide( $this->query() )->getScore() );
	}

	/**
	 * A route for something the wiki does not run is left out rather than built and made to
	 * veto every query.
	 */
	public function testRequiresLeavesTheRouteOut() {
		$entry = [ 'context' => 'ctx', 'score' => 1.0, 'requires' => 'SomeSetting' ];

		$this->assertNull( VotedSearchQueryRoute::fromProfileEntry( new HashSearchConfig( [] ),
			SearchQuery::SEARCH_TEXT, 'unit_test', $entry ) );
		$this->assertNotNull( VotedSearchQueryRoute::fromProfileEntry(
			new HashSearchConfig( [ 'SomeSetting' => 'on' ] ),
			SearchQuery::SEARCH_TEXT, 'unit_test', $entry ) );
	}

	/**
	 * A voter that cannot work with this config is dropped, not fatal, so an unset knob just
	 * means the signal is missing.
	 */
	public function testUnbuildableVoterIsDropped() {
		$route = VotedSearchQueryRoute::fromProfileEntry( new HashSearchConfig( [] ),
			SearchQuery::SEARCH_TEXT, 'unit_test',
			[ 'context' => 'ctx', 'score' => 1.0, 'voters' => [
				'length' => [
					'class' => \CirrusSearch\Dispatch\Voter\QueryLengthCandidateVoter::class,
					'params' => [ 'threshold_config' => 'UnsetSetting' ],
				],
				'all' => [ 'class' => \CirrusSearch\Dispatch\Voter\AllQueriesCandidateVoter::class ],
			] ] );

		$this->assertSame( [ 'all' ], $route->getVoterNames() );
	}
}
