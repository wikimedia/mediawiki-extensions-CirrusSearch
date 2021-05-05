<?php

namespace CirrusSearch;

use HashConfig;
use RequestContext;
use Wikimedia\Assert\Assert;

/**
 * Reports UserTesting bucketing decision
 *
 * See UserTestingEngine for initialization.
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
class UserTestingStatus {
	/** @var ?UserTestingStatus Bucketing decision for the main request context */
	private static $instance;

	/** @var ?string The name of the active test, or null if none */
	private $testName;

	/** @var ?string The name of the active bucket, or null if no active test */
	private $bucket;

	/**
	 * @return bool True when a bucketing decision has been made for the main
	 *  request context
	 */
	public static function hasInstance(): bool {
		return self::$instance !== null;
	}

	/**
	 * Reports bucketing decision for the main request context
	 *
	 * If not created yet, uses configuration and query string from request context
	 * to make a bucketing decision and activate that decision. This must be
	 * called as early in the request as is sensible to ensure the test configuration
	 * is applied.
	 *
	 * @return UserTestingStatus
	 */
	public static function getInstance(): UserTestingStatus {
		if ( self::$instance === null ) {
			$context = RequestContext::getMain();
			$trigger = $context->getRequest()->getVal( 'cirrusUserTesting' );
			// The current method of ensuring user testing is always initialized is
			// sloppy, if we used the context config everything that touches
			// ElasticsearchIntermediary would fail unit testing.
			if ( defined( 'MW_PHPUNIT_TEST' ) ) {
				$config = new HashConfig( [
					'CirrusSearchUserTesting' => [],
					'CirrusSearchActiveTest' => false,
				] );
			} else {
				$config = $context->getConfig();
			}
			$engine = UserTestingEngine::fromConfig( $config );
			self::$instance = $engine->decideActiveTest( $trigger );
			// The singleton here also doubles as a marker for if we've already
			// applied the test configuration. This should be the only place
			// to activate a test outside maintenance scripts.
			$engine->activateTest( self::$instance );
		}
		return self::$instance;
	}

	/**
	 * @param string $testName
	 * @param string $bucket
	 * @return UserTestingStatus status representing a test bucket active for this request
	 */
	public static function active( string $testName, string $bucket ): UserTestingStatus {
		return new self( $testName, $bucket );
	}

	/**
	 * @return UserTestingStatus status representing user testing as inactive
	 */
	public static function inactive(): UserTestingStatus {
		return new self( null, null );
	}

	private function __construct( ?string $testName, ?string $bucket ) {
		Assert::precondition( ( $testName === null ) === ( $bucket === null ),
			'Either testName and bucket are both null or both strings' );
		$this->testName = $testName;
		$this->bucket = $bucket;
	}

	/**
	 * @return bool True when a test is currently active
	 */
	public function isActive(): bool {
		return $this->testName !== null;
	}

	/**
	 * @return string
	 * @throws NoActiveTestException
	 */
	public function getTestName(): string {
		if ( $this->testName === null ) {
			throw new NoActiveTestException();
		}
		return $this->testName;
	}

	/**
	 * @return string
	 * @throws NoActiveTestException
	 */
	public function getBucket(): string {
		if ( $this->bucket === null ) {
			throw new NoActiveTestException();
		}
		return $this->bucket;
	}

	/**
	 * @return string When active returns a string that will enable the same
	 *  test configuration when provided to UserTestingEngine.
	 */
	public function getTrigger(): string {
		if ( $this->testName === null || $this->bucket == null ) {
			throw new NoActiveTestException();
		}
		return "{$this->testName}:{$this->bucket}";
	}
}
