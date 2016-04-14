<?php

namespace CirrusSearch;

use CirrusSearch\UserTesting;

/**
 * Make sure cirrus doens't break any hooks.
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
class UserTestingTest extends \MediaWikiTestCase {
	/**
	 * @beforeClass
	 */
	public static function setUpBeforeClass() {
		ElasticsearchIntermediary::resetExecutionId();
		UserTesting::resetInstance();
	}

	public function testPartitipcationInTest() {
		$config = $this->config( 'test' );
		$ut = $this->ut( $config, true );
		$this->assertEquals( true, $ut->isParticipatingIn( 'test' ) );
		$ut = $this->ut( $config, false );
		$this->assertEquals( false, $ut->isParticipatingIn( 'test' ) );
	}

	// There is no way to run this test correctly...random values mean
	// best we can do is measure distribution over a decent sample size
	public function testSamplesFairlyWithDefaultCallback() {
		$mockReq = $this->getMockBuilder( 'WebRequest' )
			->disableOriginalConstructor()
			->getMock();
		$mockReq->expects( $this->any() )
			->method( 'getIP' )
			->will( $this->returnCallback( function () { return mt_rand(); } ) );
		$mockReq->expects( $this->any() )
			->method( 'getHeader' )
			->will( $this->returnCallback( function () { return mt_rand(); } ) );

		\RequestContext::getMain()->setRequest( $mockReq );

		$config = $this->config( 'test', 3 );
		$samples = 3000;
		$expected = $samples / $config['test']['sampleRate'];
		$expectedPerBucket = $expected / count( $config['test']['buckets'] );
		$allowedError = .25;
		$buckets = array();
		for ( $i = 0; $i < $samples; ++$i ) {
			$ut = new UserTesting( $config );
			if ( $ut->isParticipatingIn( 'test' ) ) {
				$bucket = $ut->getBucket( 'test' );
				if ( isset( $buckets[$bucket] ) ) {
					$buckets[$bucket]++;
				} else {
					$buckets[$bucket] = 1;
				}
			}
		}
		unset( $buckets[''] );
		$participants = array_sum( $buckets );
		$this->assertGreaterThan( $expected * ( 1 - $allowedError ), $participants );
		$this->assertLessThan( $expected * ( 1 + $allowedError ), $participants );
		foreach ( $buckets as $bucket => $participants ) {
			$this->assertGreaterThan( $expectedPerBucket * ( 1 - $allowedError ), $participants );
			$this->assertLessThan( $expectedPerBucket * ( 1 + $allowedError ), $participants );
		}
	}

	public function testListsTestsCurrentlyParticipatingIn() {
		$config = $this->config( array( 'test', 'foo', 'bar' ) );
		$ut = $this->ut( $config, true);
		$this->assertEquals( array( 'test', 'foo', 'bar' ), $ut->getActiveTestNames() );
		$ut = $this->ut( $config, array( false, true, true ) );
		$this->assertEquals( array( 'foo', 'bar' ), $ut->getActiveTestNames() );
	}

	public function testActiveTestOverridesGlobalVariables() {
		$config = $this->config( 'test', 10, array(
			'wgCirrusSearchBoostLinks' => true,
			'dontsetthisvariable' => true,
		) );

		$this->setMwGlobals( 'wgCirrusSearchBoostLinks', false );
		$ut = $this->ut( $config, true );
		$this->assertEquals( true, $GLOBALS['wgCirrusSearchBoostLinks'] );
		$this->assertArrayNotHasKey( 'dontsetthisvariable', $GLOBALS );
		$this->setMwGlobals( 'wgCirrusSearchBoostLinks', false );
		$ut = $this->ut( $config, false );
		$this->assertEquals( false, $GLOBALS['wgCirrusSearchBoostLinks'] );
	}

	public function testDoesNotReinitializeFromGetInstance() {
		$this->setMwGlobals( array(
			'wgCirrusSearchUserTesting' => $this->config( 'test', 10, array(
				'wgCirrusSearchBoostLinks' => true,
			) ),
			'wgCirrusSearchBoostLinks' => false,
		) );
		$ut = UserTesting::getInstance( function () { return true; } );
		$this->assertEquals( true, $GLOBALS['wgCirrusSearchBoostLinks'] );
		$GLOBALS['wgCirrusSearchBoostLinks'] = false;
		$ut = UserTesting::getInstance( function () { return true; } );
		$this->assertEquals( false, $GLOBALS['wgCirrusSearchBoostLinks'] );
	}

	public function testPerBucketGlobalsOverridePerTestGlobals() {
		$this->setMwGlobals( 'wgCirrusSearchBoostLinks', false );
		$config = $this->config( 'test', 10, array(
			'wgCirrusSearchBoostLinks' => 'test',
		) );
		$config['test']['buckets']['a']['wgCirrusSearchBoostLinks'] = 'bucket';
		$config['test']['buckets']['b']['wgCirrusSearchBoostLinks'] = 'bucket';

		$ut = $this->ut( $config, true );
		$this->assertEquals( 'bucket', $GLOBALS['wgCirrusSearchBoostLinks'] );
	}

	public function providerChooseBucket() {
		return array(
			array( 'a', 0, array( 'a', 'b', 'c' ) ),
			array( 'a', 0, array( 'a', 'b', 'c', 'd' ) ),
			array( 'a', 0.24, array( 'a', 'b', 'c', 'd' ) ),
			array( 'a', 0.25, array( 'a', 'b', 'c', 'd' ) ),
			array( 'b', 0.26, array( 'a', 'b', 'c', 'd' ) ),
			array( 'b', 0.49, array( 'a', 'b', 'c', 'd' ) ),
			array( 'b', 0.50, array( 'a', 'b', 'c', 'd' ) ),
			array( 'c', 0.51, array( 'a', 'b', 'c', 'd' ) ),
			array( 'd', 1, array( 'a', 'b', 'c', 'd' ) ),
		);
	}

	/**
	 * @dataProvider providerChooseBucket
	 */
	public function testChooseBucket( $expect, $probability, array $buckets ) {
		$this->assertEquals( $expect, UserTesting::chooseBucket( $probability, $buckets ) );
	}

	protected function config( $testNames, $sampleRate = 10, $globals = array() ) {
		if ( $globals ) {
			$globals = array( 'globals' => $globals );
		}
		$config = array();
		foreach ( (array)$testNames as $name ) {
			$config[$name] = $globals + array(
				'sampleRate' => $sampleRate,
				'buckets' => array(
					'a' => array(),
					'b' => array(),
				),
			);
		}
		return $config;
	}

	protected function ut( $config, $callback ) {
		if ( is_array( $callback ) ) {
			// reverse so pop in reverse order
			$retvals = array_reverse( $callback );
			$callback = function () use ( &$retvals ) {
				$retval = array_pop( $retvals );
				return $retval ? mt_rand( 0, mt_getrandmax() ) / mt_getrandmax() : 0;
			};
		} elseif ( is_bool( $callback ) ) {
			$retval = $callback;
			$callback = function () use ( $retval ) {
				return $retval ? mt_rand( 0, mt_getrandmax() ) / mt_getrandmax() : 0;
			};
		}
		return new UserTesting( $config, $callback );
	}
}
