<?php

namespace CirrusSearch\Search;

class InterleavedResultSet extends ResultSet implements SearchMetricsProvider {
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
		parent::__construct();
		$this->results = $interleaved;
		$this->teamA = $teamA;
		$this->teamB = $teamB;
		$this->offset = $offset;

		$nested->copyTo( $this );
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
