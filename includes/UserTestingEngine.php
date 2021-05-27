<?php

namespace CirrusSearch;

use Config;
use Wikimedia\Assert\Assert;

/**
 * Decision making around user testing
 *
 * See docs/user_testing.md for more information.
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
class UserTestingEngine {
	/** @var array */
	private $tests;

	/** @var ?string */
	private $activeTest;

	/**
	 * @var callable Called with the test name, returns a float between 0 and 1
	 *  which is uniformly distributed across users and stable for an individual
	 *  user+testName combination.
	 */
	private $callback;

	/**
	 * @param array $tests
	 * @param ?string $activeTest Array key in test to enable autoenroll for, or null
	 *  for no autoenrollment.
	 * @param callable $callback Called with the test name, returns a float
	 *  between 0 and 1 which is uniformly distributed across users and stable
	 *  for an individual user+testName combination.
	 */
	public function __construct( array $tests, ?string $activeTest, callable $callback ) {
		$this->tests = $tests;
		$this->activeTest = $activeTest;
		$this->callback = $callback;
	}

	public static function fromConfig( Config $config ): UserTestingEngine {
		return new self(
			// While we shouldn't get null in normal operations, the global
			// initialization of user testing is a bit sloppy and this gets
			// invoked during ElasticsearchIntermediary unit testing, and unit
			// testing doesn't have any globally accessible config.
			$config->get( 'CirrusSearchUserTesting' ) ?? [],
			$config->get( 'CirrusSearchActiveTest' ),
			[ __CLASS__, 'stableUserProbability' ]
		);
	}

	public function decideTestByTrigger( string $trigger ): UserTestingStatus {
		if ( strpos( $trigger, ':' ) === false ) {
			return UserTestingStatus::inactive();
		}
		list( $testName, $bucket ) = explode( ':', $trigger, 2 );
		if ( isset( $this->tests[$testName]['buckets'][$bucket] ) ) {
			return UserTestingStatus::active( $testName, $bucket );
		} else {
			return UserTestingStatus::inactive();
		}
	}

	public function decideTestByAutoenroll(): UserTestingStatus {
		if ( $this->activeTest === null || !isset( $this->tests[$this->activeTest] ) ) {
			return UserTestingStatus::inactive();
		}
		$bucketProbability = call_user_func( $this->callback, $this->activeTest );
		$bucket = self::chooseBucket( $bucketProbability, array_keys(
			$this->tests[$this->activeTest]['buckets'] ) );
		return UserTestingStatus::active( $this->activeTest, $bucket );
	}

	public function decideActiveTest( ?string $trigger ): UserTestingStatus {
		if ( $trigger !== null ) {
			return $this->decideTestByTrigger( $trigger );
		} elseif ( MW_ENTRY_POINT == 'index' ) {
			return $this->decideTestByAutoenroll();
		} else {
			return UserTestingStatus::inactive();
		}
	}

	/**
	 * If provided status is in an active state enable the related configuration.
	 *
	 * @param UserTestingStatus $status
	 */
	public function activateTest( UserTestingStatus $status ) {
		if ( !$status->isActive() ) {
			return;
		}
		// boldly assume we created this status and it exists
		$testConfig = $this->tests[$status->getTestName()];
		$globals = array_merge(
			$testConfig['globals'] ?? [],
			$testConfig['buckets'][$status->getBucket()]['globals'] ?? [] );

		foreach ( $globals as $key => $value ) {
			if ( array_key_exists( $key, $GLOBALS ) ) {
				$GLOBALS[$key] = $value;
			}
		}
	}

	/**
	 * @param float $probability A number between 0 and 1
	 * @param string[] $buckets List of buckets to choose from.
	 * @return string The chosen bucket.
	 */
	public static function chooseBucket( float $probability, array $buckets ): string {
		$n = count( $buckets );
		$pos = (int)min( $n - 1, $n * $probability );
		return $buckets[ $pos ];
	}

	/**
	 * Converts a hex string into a probability between 0 and 1.
	 * Retains uniform distribution of incoming hash string.
	 *
	 * @param string $hash
	 * @return float Probability between 0 and 1
	 */
	public static function hexToProbability( string $hash ): float {
		Assert::parameter( strlen( $hash ) > 0, '$hash',
			'Provided string must not be empty' );
		$len = strlen( $hash );
		$sum = 0;
		// TODO: Since the input is from a cryptographic hash simply
		// truncating is probably equally correct.
		for ( $i = 0; $i < $len; $i += 4 ) {
			$piece = substr( $hash, $i, 4 );
			$dec = hexdec( $piece );
			// xor will retain the uniform distribution
			$sum ^= $dec;
		}
		return $sum / ( ( 1 << 16 ) - 1 );
	}

	/**
	 * @param string $testName
	 * @return float Returns a value between 0 and 1 that is uniformly
	 *  distributed between users, but constant for a single user+test
	 *  combination.
	 */
	public static function stableUserProbability( string $testName ): float {
		$hash = Util::generateIdentToken( $testName );
		return self::hexToProbability( $hash );
	}
}
