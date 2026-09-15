<?php

namespace CirrusSearch\Test;

use CirrusSearch\Dispatch\Voter\TitleMatchLookup;
use CirrusSearch\Search\SearchQuery;

/**
 * A title match lookup that answers the same thing about every query, so that
 * TitleMatchVetoVoter can be tested without a search backend.
 */
class StaticTitleMatchLookup implements TitleMatchLookup {
	private ?bool $answer;

	/** @var int Number of queries looked up. */
	private int $calls = 0;

	/**
	 * @param bool|null $answer what to report, null for a lookup that cannot answer
	 */
	public function __construct( ?bool $answer ) {
		$this->answer = $answer;
	}

	public function matchesExistingTitle( SearchQuery $query ): ?bool {
		$this->calls++;
		return $this->answer;
	}

	public function getCallCount(): int {
		return $this->calls;
	}
}
