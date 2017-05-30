<?php

namespace CirrusSearch\Elastica;

class MatchNoneTest extends \PHPUnit_Framework_TestCase {
	public function testMatchNone() {
		$query = new MatchNone();
		$expectedArray = ['match_none' => new \stdClass()];
		$this->assertEquals($expectedArray, $query->toArray());
	}

	public function testBackPorts() {
		$this->assertFalse(
			class_exists( \Elastica\Query\MatchNone::class ),
			"MatchNone is now in elastica please remove this backport"
		);
	}
}
