<?php

namespace CirrusSearch\Job;

use CirrusSearch\CirrusIntegrationTestCase;

/**
 * Test for MassIndex job.
 *
 * @license GPL-2.0-or-later
 *
 * @group CirrusSearch
 * @covers \CirrusSearch\Job\MassIndex
 */
class MassIndexTest extends CirrusIntegrationTestCase {
	/**
	 * @dataProvider provideWorkItemCountTestCases
	 */
	public function testWorkItemCount( $pageDBKeys, $expected ) {
		$job = new MassIndex( [
			'pageDBKeys' => $pageDBKeys,
		] );
		$this->assertEquals( $expected, $job->workItemCount() );
	}

	public static function provideWorkItemCountTestCases() {
		return [
			[ [], 0 ],
			[ [ 'Foo' ], 1 ],
			[ [ 'Cat', 'Cow', 'Puppy' ], 3 ],
		];
	}
}
