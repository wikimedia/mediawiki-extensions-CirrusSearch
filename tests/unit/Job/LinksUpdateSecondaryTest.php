<?php

namespace CirrusSearch\Job;

use \MediaWikiTestCase;
use \Title;

/**
 * Test for LinksUpdateSecondary job.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */
class LinksUpdateSecondaryTest extends MediaWikiTestCase {
	/**
	 * @dataProvider workItemCountTestCases
	 */
	public function testWorkItemCount( $addedLinks, $removedLinks, $expected ) {
		$job = new LinksUpdateSecondary( Title::newMainPage(), array(
			'addedLinks' => $addedLinks,
			'removedLinks' => $removedLinks,
		) );
		$this->assertEquals( $expected, $job->workItemCount() );
	}

	public static function workItemCountTestCases() {
		return array(
			array( array(), array(), 0 ),
			array( array( 'Foo' ), array(), 1 ),
			array( array(), array( 'Bar' ), 1 ),
			array( array( 'Cat', 'Cow', 'Puppy' ), array( 'Lorax' ), 4 ),
		);
	}
}
