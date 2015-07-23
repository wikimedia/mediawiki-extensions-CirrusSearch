<?php

namespace CirrusSearch;

/**
 * Handles decisions arround if the current request is a member of any
 * test currently being run. This initial implementation is per-request
 * but could be extended to keep the same user in the same test/bucket
 * over multiple requests.
 *
 * $wgCirrusSearchUserTesting = array(
 *     'someTest' => array(
 *         'sampleRate' => 100, // sample 1 in 100 occurances
 *         'buckets' => array(
 *             'a' => array(
 *                 // control bucket, retain defaults
 *             ),
 *             'b' => array(
 *                 'wgCirrusSearchBoostLinks' => 42,
 *             ),
 *             ...
 *         ),
 *     ,
 *     ...
 * );
 *
 * Per test configuration options:
 *
 * * sampleRate - A number >= 1 that specifies the sampling rate of the tests.
 *                1 in sampleRate requests will participate in the test.
 * * globals    - A map from global variable name to value to set for all requests
 *                participating in the test.
 * * buckets    - A map from bucket name to map from global variable name to value
 *                to set for all requests that are a member of the chosen bucket.
 *                Per-bucket variables override per-test global variables.
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
class UserTesting {
	/**
	 * @var UserTesting|null Shared instance of this class configured with
	 *  $wgCirrusSearchUserTesting
	 */
	private static $instance;

	/**
	 * @var array Map from test name to the bucket the request is in.
	 */
	protected $tests = array();

	/**
	 * Returns a stable instance based on $wgCirrusSearchUserTesting
	 * global configuration.
	 *
	 * @var callable|null $callback
	 * @return self
	 */
	public static function getInstance( $callback = null ) {
		global $wgCirrusSearchUserTesting;
		if ( self::$instance === null ) {
			self::$instance = new self( $wgCirrusSearchUserTesting, $callback );
		}
		return self::$instance;
	}

	/**
	 * @var array $config
	 * @var callable|null $callback
	 */
	public function __construct( array $config, $callback = null ) {
		if ( $callback === null ) {
			$callback = array( __CLASS__, 'oneIn' );
		}
		foreach ( $config as $testName => $testConfig ) {
			if ( !isset( $testConfig['sampleRate'] ) ) {
				continue;
			}
			if ( call_user_func( $callback, $testName, $testConfig['sampleRate'] ) ) {
				$this->activateTest( $testName, $testConfig );
			}
		}
	}

	/**
	 * @var string $testName Name of a test being run
	 * @return bool True when the request is participating in the named test
	 */
	public function isParticipatingIn( $testName ) {
		return isset( $this->tests[$testName] );
	}

	/**
	 * @var string $testName Name of a test being run
	 * @return string The bucket the request has been placed in for the named
	 *  test. If the request is not participating in the test the bucket will
	 *  be the empty string.
	 */
	public function getBucket( $testName ) {
		return isset( $this->tests[$testName] ) ? $this->tests[$testName] : '';
	}

	/**
	 * @return string[] List of tests that are active for the current request.
	 */
	public function getActiveTestNames() {
		return array_keys( $this->tests );
	}

	/**
	 * @var string $testName Name of the test to activate.
	 * @var array $testConfig Configuration of the test to activate.
	 */
	protected function activateTest( $testName, array $testConfig ) {
		$this->tests[$testName] = '';
		$globals = array();
		if ( isset( $testConfig['globals'] ) ) {
			$globals = $testConfig['globals'];
		}
		if ( isset( $testConfig['buckets'] ) ) {
			$bucket = array_rand( $testConfig['buckets'] );
			$this->tests[$testName] = $bucket;
			$globals = array_merge( $globals, $testConfig['buckets'][$bucket] );
		}

		foreach ( $globals as $key => $value ) {
			if ( array_key_exists( $key, $GLOBALS ) ) {
				$GLOBALS[$key] = $value;
			}
		}
	}

	/**
	 * Converts a hex string into a probability between 0 and 1.
	 * Retains uniform distribution of incoming hash string.
	 *
	 * @param string $hash
	 * @return float Probability between 0 and 1
	 */
	static public function hexToProbability( $hash ) {
		if ( strlen( $hash ) === 0 ) {
			throw new \RuntimeException( 'Empty hash provided' );
		}
		$len = strlen( $hash );
		$sum = null;
		for ( $i = 0; $i < $len; $i += 4) {
			$piece = substr( $hash, $i, 4 );
			$dec = hexdec( $piece );
			if ( $sum === null ) {
				$sum = $dec;
			} else {
				// exclusive OR will retain the uniform
				// distribution
				$sum = $sum ^ $dec;
			}
		}
		return $sum / ((1<<16)-1);
	}

	/**
	 * @var integer $sampleRate
	 * @return bool True for 1 in $sampleRate calls to this method.
	 */
	static public function oneIn( $testName, $sampleRate ) {
		$hash = ElasticsearchIntermediary::generateIdentToken( $testName );
		$probability = self::hexToProbability( $hash );
		return 1 / $sampleRate > $probability;
	}
}
