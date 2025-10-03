<?php

namespace CirrusSearch\SecondTry;

interface SecondTrySearch {
	/**
	 * Build a list of candidate searches to try.
	 * Empty array if nothing is worth retrying.
	 * @param string $searchQuery
	 * @return string[]
	 */
	public function candidates( string $searchQuery ): array;
}
