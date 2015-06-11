<?php

namespace CirrusSearch;

use \MediaWikiTestCase;
use \Elastica\Exception\InvalidException;

/**
 * Test Util functions.
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
class UtilTest extends MediaWikiTestCase {
	/**
	 * @dataProvider recursiveSameTestCases
	 */
	public function testRecursiveSame( $same, $lhs, $rhs ) {
		$this->assertEquals( $same, Util::recursiveSame( $lhs, $rhs ) );
	}

	public static function recursiveSameTestCases() {
		return array(
			array( true, array(), array() ),
			array( false, array( true ), array() ),
			array( false, array( true ), array( false ) ),
			array( true, array( true ), array( true ) ),
			array( false, array( 1 ), array( 2 ) ),
			array( false, array( 1, 2 ), array( 2, 1 ) ),
			array( true, array( 1, 2, 3 ), array( 1, 2, 3 ) ),
			array( false, array( array( 1 ) ), array( array( 2 ) ) ),
			array( true, array( array( 1 ) ), array( array( 1 ) ) ),
			array( true, array( 'candle' => array( 'wax' => 'foo' ) ), array( 'candle' => array( 'wax' => 'foo' ) ) ),
			array( false, array( 'candle' => array( 'wax' => 'foo' ) ), array( 'candle' => array( 'wax' => 'bar' ) ) ),
		);
	}

	/**
	 * @dataProvider cleanUnusedFieldsProvider
	 */
	public function testCleanUnusedFields( $data, $properties, $expect ) {
		$result = Util::cleanUnusedFields( $data, $properties );
		$this->assertArrayEquals( $result, $expect );
	}

	public static function cleanUnusedFieldsProvider() {
		return array(
			// sample
			array(
				// data
				array(
					'title' => "I'm a title",
					'useless' => "I'm useless",
				),
				// properties
				array(
					'title' => 'params-for-title'
				),
				// expect
				array(
					'title' => "I'm a title",
				),
			),
			// Flow data - untouched
			array(
				// data (as seen in https://gerrit.wikimedia.org/r/#/c/195889/1//COMMIT_MSG)
				array(
					'namespace' => 1,
					'namespace_text' => "Talk",
					'pageid' => 2,
					'title' => "Main Page",
					'timestamp' => "2014-02-07T01:42:57Z",
					'update_timestamp' => "2014-02-25T14:12:40Z",
					'revisions' => array(
						array(
							'id' => "rpvwvywl9po7ih77",
							'text' => "topic title content",
							'source_text' => "topic title content",
							'moderation_state' => "",
							'timestamp' => "2014-02-07T01:42:57Z",
							'update_timestamp' => "2014-02-07T01:42:57Z",
							'type' => "topic"
						),
						array(
							'id' => "ropuzninqgyf19ko",
							'text' => "reply content",
							'source_text' => "reply '''content'''",
							'moderation_state' => "hide",
							'timestamp' => "2014-02-25T14:12:40Z",
							'update_timestamp' => "2014-02-25T14:12:40Z",
							'type' => "post"
						),
					)
				),
				// properties (as seen in https://gerrit.wikimedia.org/r/#/c/161251/26/includes/Search/maintenance/MappingConfigBuilder.php)
				array(
					'namespace' => array( '...' ),
					'namespace_text' => array( '...' ),
					'pageid' => array( '...' ),
					'title' => array( '...' ),
					'timestamp' => array( '...' ),
					'update_timestamp' => array( '...' ),
					'revisions' => array(
						'properties' => array(
							'id' => array( '...' ),
							'text' => array( '...' ),
							'source_text' => array( '...' ),
							'moderation_state' => array( '...' ),
							'timestamp' => array( '...' ),
							'update_timestamp' => array( '...' ),
							'type' => array( '...' ),
						)
					),
				),
				// expect
				array(
					'namespace' => 1,
					'namespace_text' => "Talk",
					'pageid' => 2,
					'title' => "Main Page",
					'timestamp' => "2014-02-07T01:42:57Z",
					'update_timestamp' => "2014-02-25T14:12:40Z",
					'revisions' => array(
						array(
							'id' => "rpvwvywl9po7ih77",
							'text' => "topic title content",
							'source_text' => "topic title content",
							'moderation_state' => "",
							'timestamp' => "2014-02-07T01:42:57Z",
							'update_timestamp' => "2014-02-07T01:42:57Z",
							'type' => "topic"
						),
						array(
							'id' => "ropuzninqgyf19ko",
							'text' => "reply content",
							'source_text' => "reply '''content'''",
							'moderation_state' => "hide",
							'timestamp' => "2014-02-25T14:12:40Z",
							'update_timestamp' => "2014-02-25T14:12:40Z",
							'type' => "post"
						),
					)
				),
			),
			// Flow data - deleted columns in config
			array(
				// data (as seen in https://gerrit.wikimedia.org/r/#/c/195889/1//COMMIT_MSG)
				array(
					'namespace' => 1,
					'namespace_text' => "Talk",
					'pageid' => 2,
					'title' => "Main Page",
					'timestamp' => "2014-02-07T01:42:57Z",
					'update_timestamp' => "2014-02-25T14:12:40Z",
					'revisions' => array(
						array(
							'id' => "rpvwvywl9po7ih77",
							'text' => "topic title content",
							'source_text' => "topic title content",
							'moderation_state' => "",
							'timestamp' => "2014-02-07T01:42:57Z",
							'update_timestamp' => "2014-02-07T01:42:57Z",
							'type' => "topic"
						),
						array(
							'id' => "ropuzninqgyf19ko",
							'text' => "reply content",
							'source_text' => "reply '''content'''",
							'moderation_state' => "hide",
							'timestamp' => "2014-02-25T14:12:40Z",
							'update_timestamp' => "2014-02-25T14:12:40Z",
							'type' => "post"
						),
					)
				),
				// properties (as seen in https://gerrit.wikimedia.org/r/#/c/161251/26/includes/Search/maintenance/MappingConfigBuilder.php)
				array(
					'namespace' => array( '...' ),
					'namespace_text' => array( '...' ),
					'pageid' => array( '...' ),
					'title' => array( '...' ),
					// deleted timestamp & update_timestamp columns
					'revisions' => array(
						'properties' => array(
							'id' => array( '...' ),
							'text' => array( '...' ),
							'source_text' => array( '...' ),
							'moderation_state' => array( '...' ),
							// deleted timestamp & update_timestamp columns
							'type' => array( '...' ),
						)
					),
				),
				// expect
				array(
					'namespace' => 1,
					'namespace_text' => "Talk",
					'pageid' => 2,
					'title' => "Main Page",
					// deleted timestamp & update_timestamp columns
					'revisions' => array(
						array(
							'id' => "rpvwvywl9po7ih77",
							'text' => "topic title content",
							'source_text' => "topic title content",
							'moderation_state' => "",
							// deleted timestamp & update_timestamp columns
							'type' => "topic"
						),
						array(
							'id' => "ropuzninqgyf19ko",
							'text' => "reply content",
							'source_text' => "reply '''content'''",
							'moderation_state' => "hide",
							// deleted timestamp & update_timestamp columns
							'type' => "post"
						),
					)
				),
			),
		);
	}

	public function testBackoffDelay() {
		for ( $i = 0; $i < 100; $i++ ) {
			$this->assertLessThanOrEqual( 16, Util::backoffDelay( 1 ) );
			$this->assertLessThanOrEqual( 256, Util::backoffDelay( 5 ) );
		}
	}

	public function testWithRetry() {
		$calls = 0;
		$func = function() use ( &$calls ) {
			$calls++;
			if( $calls <= 5 ) {
				throw new InvalidException();
			}
		};
		$self = $this;
		$errorCallbackCalls = 0;
		Util::withRetry( 5, $func, function ($e, $errCount) use ( $self, &$errorCallbackCalls ) {
			$errorCallbackCalls++;
			$self->assertEquals( "Elastica\Exception\InvalidException", get_class( $e ) );
		} );
		$this->assertEquals( 6, $calls );
		$this->assertEquals( 5, $errorCallbackCalls );
	}
}
