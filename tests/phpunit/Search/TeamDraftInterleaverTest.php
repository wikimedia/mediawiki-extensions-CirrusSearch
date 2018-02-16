<?php

namespace CirrusSearch\Search;

class TeamDraftInterleaverTest extends \PHPUnit\Framework\TestCase {
	public function testInterleaveResults() {
		// Construct some pointless array with all overlapping values
		$limit = 20;
		$a = range( 5, 5 + $limit - 1 );
		$b = range( 1, 1 + $limit - 1 );
		$a = array_combine( $a, $a );
		$b = array_combine( $b, $b );

		for ( $i = 0; $i < 10; ++$i ) {
			// Use a constant seed to allow determinism
			mt_srand( 12345 * $i );
			list( $interleave, $teamA, $teamB, $aOffset ) = TeamDraftInterleaver::interleaveResults( $a, $b, $limit );
			// Very basic assertions about the shape of results
			$this->assertCount( 10, $teamA );
			$this->assertCount( 10, $teamB );
			$this->assertCount( 0, array_intersect( $teamA, $teamB ) );
			$this->assertCount( $limit, $interleave );

			// Verify offset is last used iteam in a. Note that this isn't
			// perfect, there could be items in a after this that were
			// used by B, but it's good enough.
			// Remember that offset is < 0, because its a number where
			// the next page starts at $offset + $limit (0 indexed).
			$nextIdx = $aOffset + $limit;
			$last = array_slice( $a, $nextIdx - 1, 1 );
			$this->assertCount( 1, array_intersect( $interleave, $last ) );
			$next = array_slice( $a, $nextIdx, 1 );
			$this->assertCount( 0, array_intersect( $interleave, $next ) );
		}
	}

	public function testTeamAExhausted() {
		$a = range( 100, 104 );
		$b = range( 0, 20 );
		$a = array_combine( $a, $a );
		$b = array_combine( $b, $b );

		list( $interleave, $teamA, $teamB, ) = TeamDraftInterleaver::interleaveResults( $a, $b, 15 );
		$this->assertCount( 15, $interleave );
		$this->assertCount( 5, $teamA );
		$this->assertCount( 10, $teamB );
	}

	public function testTeamBExhausted() {
		$a = range( 100, 120 );
		$b = range( 0, 4 );
		$a = array_combine( $a, $a );
		$b = array_combine( $b, $b );

		list( $interleave, $teamA, $teamB, ) = TeamDraftInterleaver::interleaveResults( $a, $b, 11 );
		$this->assertCount( 11, $interleave );
		$this->assertCount( 6, $teamA );
		$this->assertCount( 5, $teamB );
	}

	public function testNotEnoughResults() {
		$a = range( 100, 102 );
		$b = range( 0, 4 );
		$a = array_combine( $a, $a );
		$b = array_combine( $b, $b );

		list( $interleave, $teamA, $teamB, ) = TeamDraftInterleaver::interleaveResults( $a, $b, 20 );
		$this->assertCount( 8, $interleave );
		$this->assertCount( 3, $teamA );
		$this->assertCount( 5, $teamB );
	}

	public function testOverlap() {
		$a = range( 0, 9 );
		$b = range( 0, 9 );
		$a = array_combine( $a, $a );
		$b = array_combine( $b, $b );

		list( $interleave, $teamA, $teamB, ) = TeamDraftInterleaver::interleaveResults( $a, $b, 20 );
		$this->assertCount( 10, $interleave );
		$this->assertCount( 5, $teamA );
		$this->assertCount( 5, $teamB );
	}

}
