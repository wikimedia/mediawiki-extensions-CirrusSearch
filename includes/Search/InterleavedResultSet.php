<?php

namespace CirrusSearch\Search;

use ArrayIterator;

class InterleavedResultSet extends ResultSet implements SearchMetricsProvider {
	/** @var ArrayIterator */
	private $interleaved;
	/** @var string[] Doc ID's belonging to team A */
	private $teamA;
	/** @var string[] Doc ID's belonging to team B */
	private $teamB;
	/** @var int Offset to calculate next unused result in team A */
	private $offset;

	/**
	 * @param ResultSet $nested Original result set for team A (control)
	 * @param Result[] $interleaved Interleaved results
	 * @param string[] $teamA Document id's belonging to team A
	 * @param string[] $teamB Document id's belonging to team B
	 * @param int $offset Offset to calculate next unused result in team A
	 */
	public function __construct(
		ResultSet $nested,
		array $interleaved,
		array $teamA,
		array $teamB,
		$offset
	) {
		$this->interleaved = new ArrayIterator( $interleaved );
		$this->teamA = $teamA;
		$this->teamB = $teamB;
		$this->offset = $offset;

		$nested->copyTo( $this );
	}

	public function next() {
		$current = $this->interleaved->current();
		if ( $current ) {
			$this->interleaved->next();
			return $current;
		}
		return false;
	}

	public function rewind() {
		$this->interleaved->rewind();
	}

	public function getMetrics() {
		return [
			'wgCirrusSearchTeamDraft' => [
				'a' => $this->teamA,
				'b' => $this->teamB,
			],
		];
	}

	public function getOffset() {
		return $this->offset;
	}
}
