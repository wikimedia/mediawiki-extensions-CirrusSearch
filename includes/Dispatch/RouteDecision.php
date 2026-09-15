<?php

namespace CirrusSearch\Dispatch;

use CirrusSearch\Dispatch\Voter\RouteVote;
use Wikimedia\Assert\Assert;

/**
 * What one route decided about one query, and why.
 *
 * Reports the profile context that was chosen by query routing.
 * Carries debug information about how this route was selected.
 *
 * @see VotedSearchQueryRoute
 * @license GPL-2.0-or-later
 */
class RouteDecision {
	/** The score of a route that will not take the query. */
	public const REJECT_ROUTE = 0.0;

	/** A voter forced the route, and the voters after it were not asked. */
	public const REASON_FORCED = 'forced';

	/** A voter vetoed the route. The votes say which one. */
	public const REASON_VETOED = 'vetoed';

	/** Every voter abstained, and a route is something a query has to be proposed for. */
	public const REASON_NO_CANDIDATE = 'no_candidate';

	/** A voter proposed the route and no voter vetoed it. */
	public const REASON_CANDIDATE = 'candidate';

	/** No route accepted the query, so it went to the default route the profile names. */
	public const REASON_DEFAULT = 'default';

	private string $profileContext;
	private bool $accepted;
	private string $reason;
	private float $score;

	/** @var array<string, RouteVote> */
	private array $votes;

	/**
	 * @param string $profileContext profile context the route would select
	 * @param bool $accepted whether the query goes on the route
	 * @param string $reason one of the REASON_* constants
	 * @param float $score the bid, REJECT_ROUTE when the route was not accepted
	 * @param array<string, RouteVote> $votes votes collected, keyed by voter name, in the order
	 *  the voters were asked
	 */
	public function __construct(
		string $profileContext,
		bool $accepted,
		string $reason,
		float $score,
		array $votes = []
	) {
		Assert::parameter( $score >= self::REJECT_ROUTE && $score <= 1.0, '$score',
			"must be between 0.0 and 1.0, $score given" );
		// The election reads isAccepted() and then ranks on the score, so a decision that
		// says one thing and bids another would be skipped without anybody noticing.
		Assert::parameter( $accepted === ( $score > self::REJECT_ROUTE ), '$score',
			$accepted
				? "an accepted route must bid more than " . self::REJECT_ROUTE
				: "a rejected route bids " . self::REJECT_ROUTE . ", $score given" );
		$this->profileContext = $profileContext;
		$this->accepted = $accepted;
		$this->reason = $reason;
		$this->score = $score;
		$this->votes = $votes;
	}

	public function isAccepted(): bool {
		return $this->accepted;
	}

	public function getProfileContext(): string {
		return $this->profileContext;
	}

	/**
	 * @return string one of the REASON_* constants
	 */
	public function getReason(): string {
		return $this->reason;
	}

	/**
	 * @return float the bid, REJECT_ROUTE when the route was not accepted
	 */
	public function getScore(): float {
		return $this->score;
	}

	/**
	 * @return array<string, RouteVote> keyed by voter name
	 */
	public function getVotes(): array {
		return $this->votes;
	}

	/**
	 * @return array the decision as it appears in debug output
	 */
	public function toArray(): array {
		return [
			'accepted' => $this->accepted,
			'reason' => $this->reason,
			'context' => $this->profileContext,
			'score' => $this->score,
			'votes' => array_map( static fn ( RouteVote $vote ) => $vote->value, $this->votes ),
		];
	}
}
