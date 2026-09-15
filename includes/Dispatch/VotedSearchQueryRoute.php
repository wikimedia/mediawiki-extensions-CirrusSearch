<?php

namespace CirrusSearch\Dispatch;

use CirrusSearch\Dispatch\Voter\RouteVote;
use CirrusSearch\Dispatch\Voter\RouteVoter;
use CirrusSearch\Profile\SearchProfileException;
use CirrusSearch\Search\SearchQuery;
use CirrusSearch\SearchConfig;
use Wikimedia\Assert\Assert;

/**
 * A route that decides by running a vote.
 *
 * The route checks an ordered list of voters and aggregates their decisions:
 *
 * - If a VETO or FORCE is returned stop immediately. Do not ask any more voters.
 * - If one or more voters return CANDIDATE then return the a decision using the constant score.
 *   The route that has a CANDIDATE and the highest score wins.
 *
 * Voter configuration comes from the dispatch profile. Put the cheap voters
 * first. A veto or a force stops evaluation, and a voter is free to talk to the
 * search backend.
 *
 * Voters must be stateless per query. The profile service is cached per SearchConfig, so one
 * route object answers the original query, the did-you-mean rewrite, etc.
 *
 * @see \CirrusSearch\Dispatch\Voter\RouteVoter
 * @license GPL-2.0-or-later
 */
class VotedSearchQueryRoute implements SearchQueryRoute {
	/** Profile key naming the profile context this route selects. */
	public const ENTRY_CONTEXT = 'context';

	/** Profile key holding the score this route bids when it accepts a query. */
	public const ENTRY_SCORE = 'score';

	/** Profile key naming a setting that must be truthy for this route to exist at all. */
	public const ENTRY_REQUIRES = 'requires';

	/** Profile key holding the voters to ask, in order, keyed by name. */
	public const ENTRY_VOTERS = 'voters';

	private string $name;
	private string $searchEngineEntryPoint;
	private string $profileContext;
	private float $score;

	/** @var array<string, RouteVoter> Voters to ask, in order, keyed by name. */
	private array $voters;

	/**
	 * @param string $name name this route takes in the dispatch table
	 * @param string $searchEngineEntryPoint
	 * @param string $profileContext
	 * @param float $score score bid when the voters accept a query
	 * @param array<string, RouteVoter> $voters voters to ask, in order, keyed by name
	 */
	public function __construct(
		string $name,
		string $searchEngineEntryPoint,
		string $profileContext,
		float $score,
		array $voters
	) {
		$this->name = $name;
		$this->searchEngineEntryPoint = $searchEngineEntryPoint;
		$this->profileContext = $profileContext;
		$this->score = $score;
		$this->voters = $voters;
	}

	/**
	 * Build one route out of its entry in a dispatch profile.
	 *
	 * @param SearchConfig $config config of the wiki being searched
	 * @param string $searchEngineEntryPoint
	 * @param string $name key the entry has in the profile
	 * @param array $entry the entry
	 * @return self|null null when the entry names a setting the wiki has not set, which is how
	 *  a route for a feature the wiki does not run is left out rather than vetoing every query
	 * @throws SearchProfileException when the entry is malformed
	 */
	public static function fromProfileEntry(
		SearchConfig $config,
		string $searchEngineEntryPoint,
		string $name,
		array $entry
	): ?self {
		$requires = $entry[self::ENTRY_REQUIRES] ?? null;
		if ( $requires !== null && !$config->get( $requires ) ) {
			return null;
		}
		if ( !isset( $entry[self::ENTRY_CONTEXT] ) ) {
			throw new SearchProfileException( "Invalid route $name: missing 'context'" );
		}
		if ( !isset( $entry[self::ENTRY_SCORE] ) ) {
			throw new SearchProfileException( "Invalid route $name: missing 'score'" );
		}
		// Cast before comparing. A profile written with 1 rather than 1.0 would otherwise slip
		// past the check the dispatch service makes for two routes claiming the max score.
		$score = (float)$entry[self::ENTRY_SCORE];
		if ( !( $score > 0.0 ) || $score > 1.0 ) {
			throw new SearchProfileException(
				"Invalid route $name: score must be greater than 0 and at most 1, $score given" );
		}
		$voters = [];
		foreach ( $entry[self::ENTRY_VOTERS] ?? [] as $voterName => $voterDef ) {
			$voter = self::buildVoter( $config, $name, $voterName, $voterDef );
			if ( $voter !== null ) {
				$voters[$voterName] = $voter;
			}
		}
		return new self( $name, $searchEngineEntryPoint, $entry[self::ENTRY_CONTEXT], $score, $voters );
	}

	/**
	 * @param SearchConfig $config
	 * @param string $routeName
	 * @param string $voterName
	 * @param array $voterDef
	 * @return RouteVoter|null null when the voter cannot work with this config
	 * @throws SearchProfileException
	 */
	private static function buildVoter(
		SearchConfig $config,
		string $routeName,
		string $voterName,
		array $voterDef
	): ?RouteVoter {
		if ( !isset( $voterDef['class'] ) ) {
			throw new SearchProfileException(
				"Invalid RouteVoter: missing 'class' definition for $voterName in route $routeName" );
		}
		$clazz = $voterDef['class'];
		if ( !class_exists( $clazz ) ) {
			throw new SearchProfileException( "Invalid RouteVoter: unknown class $clazz" );
		}
		if ( !is_subclass_of( $clazz, RouteVoter::class ) ) {
			throw new SearchProfileException(
				"Invalid RouteVoter: $clazz must implement " . RouteVoter::class );
		}
		return $clazz::build( $config, $voterDef['params'] ?? [] );
	}

	public function decide( SearchQuery $query ): RouteDecision {
		Assert::parameter( $query->getSearchEngineEntryPoint() === $this->searchEngineEntryPoint,
			'query',
			"must be {$this->searchEngineEntryPoint} but {$query->getSearchEngineEntryPoint()} given." );

		$votes = [];
		$candidate = false;
		foreach ( $this->voters as $name => $voter ) {
			$vote = $voter->vote( $query );
			$votes[$name] = $vote;
			if ( $vote === RouteVote::Veto ) {
				return $this->decision( false, RouteDecision::REASON_VETOED, $votes );
			}
			if ( $vote === RouteVote::Force ) {
				return $this->decision( true, RouteDecision::REASON_FORCED, $votes );
			}
			$candidate = $candidate || $vote === RouteVote::Candidate;
		}
		return $candidate
			? $this->decision( true, RouteDecision::REASON_CANDIDATE, $votes )
			: $this->decision( false, RouteDecision::REASON_NO_CANDIDATE, $votes );
	}

	/**
	 * @param bool $accepted
	 * @param string $reason
	 * @param array<string, RouteVote> $votes
	 * @return RouteDecision
	 */
	private function decision( bool $accepted, string $reason, array $votes ): RouteDecision {
		return new RouteDecision( $this->profileContext, $accepted, $reason,
			$accepted ? $this->score : RouteDecision::REJECT_ROUTE, $votes );
	}

	public function getName(): string {
		return $this->name;
	}

	public function getSearchEngineEntryPoint(): string {
		return $this->searchEngineEntryPoint;
	}

	public function getProfileContext(): string {
		return $this->profileContext;
	}

	/**
	 * @return string[] names of the voters this route asks, in order
	 */
	public function getVoterNames(): array {
		return array_keys( $this->voters );
	}
}
