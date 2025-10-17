<?php

namespace CirrusSearch\SecondTry;

use Wikimedia\Assert\Assert;

/**
 * Runner responsible for determining second try search candidates given a determined profile.
 */
class SecondTryRunner {
	/**
	 * @var array<string, SecondTrySearch> SecondTrySearch indexed by name
	 */
	private array $strategies;
	/**
	 * @var array<string,float> weights of the SecondTrySearch strategies indexed by name
	 */
	private array $weights;

	/**
	 * @param array<string, SecondTrySearch> $strategies SecondTrySearch indexed by name
	 * @param array<string,float> $weights of the SecondTrySearch strategies indexed by name
	 */
	public function __construct( array $strategies, array $weights ) {
		Assert::invariant( array_keys( $strategies ) === array_keys( $weights ),
			'$strategies and $weights must be indexed with the same keys' );
		$this->strategies = $strategies;
		$this->weights = $weights;
	}

	/**
	 * @param string $query input query
	 * @return array<string, string[]> set of candidates indexed by strategy
	 * @see SecondTryRunner::weights(string) for the associated weight
	 */
	public function candidates( string $query ): array {
		$result = [];
		foreach ( $this->strategies as $name => $strategy ) {
			$candidates = $strategy->candidates( $query );
			if ( $candidates !== [] ) {
				$result[$name] = $candidates;
			}
		}
		return $result;
	}

	/**
	 * Get the weight associated to the strategy.
	 * Useful to determine the relative importance of the various strategies used by this runner.
	 * @param string $name name of the strategy
	 * @return float weight for the given strategy
	 */
	public function weight( string $name ): float {
		return $this->weights[$name];
	}
}
