<?php

namespace CirrusSearch;

/**
 * @covers \CirrusSearch\UserTestingStatus
 * @covers \CirrusSearch\UserTestingEngine
 * @group CirrusSearch
 */
class UserTestingEngineTest extends CirrusTestCase {
	private const CONFIG = [
		'some_test' => [
			'buckets' => [
				'a' => [],
				'b' => [],
			],
		],
	];

	public static function one( string $testName ): float {
		return 1.0;
	}

	public function testUnconfigured() {
		$engine = new UserTestingEngine( [], null, [ __CLASS__, 'one' ] );
		$this->assertFalse( $engine->decideTestByTrigger( '' )->isActive() );
		$this->assertFalse( $engine->decideTestByAutoenroll()->isActive() );
	}

	public function testConfiguredButNoActiveTest() {
		$engine = new UserTestingEngine( self::CONFIG, null, [ __CLASS__, 'one' ] );
		$this->assertFalse( $engine->decideTestByAutoenroll()->isActive() );
		$this->assertFalse( $engine->decideTestByTrigger( 'doesnt:exist' )->isActive() );
		$this->assertFalse( $engine->decideTestByTrigger( 'some_test:nope' )->isActive() );
		$status = $engine->decideTestByTrigger( 'some_test:a' );
		$this->assertTrue( $status->isActive() );
		$this->assertSame( 'some_test', $status->getTestName() );
		$this->assertSame( 'a', $status->getBucket() );
		$this->assertSame( 'b', $engine->decideTestByTrigger( 'some_test:b' )->getBucket() );
	}

	public function testInactiveTrigger() {
		$this->expectException( NoActiveTestException::class );
		UserTestingStatus::inactive()->getTrigger();
	}

	public function testInactiveBucket() {
		$this->expectException( NoActiveTestException::class );
		UserTestingStatus::inactive()->getBucket();
	}

	public function testInactiveTestName() {
		$this->expectException( NoActiveTestException::class );
		UserTestingStatus::inactive()->getTestName();
	}

	public function testAutoenrollment() {
		$engine = new UserTestingEngine( self::CONFIG, 'some_test', [ __CLASS__, 'one' ] );
		$status = $engine->decideTestByAutoenroll();
		$this->assertTrue( $status->isActive() );
		$this->assertSame( 'some_test', $status->getTestName() );
		// while the constant 1.0 from our callback maps to 'b' in the current
		// implementation, that seems too specific to test for.
		$this->assertContains( $status->getBucket(), [ 'a', 'b' ] );
	}

	public function testPerTestGlobalsOverridesGlobalVariables() {
		$config = self::CONFIG;
		$config['some_test']['globals'] = [
			'wgCirrusSearchRescoreProfile' => 'test',
			'dontsetthisvariable' => true,
		];

		$engine = new UserTestingEngine( $config, 'some_test', [ __CLASS__, 'one' ] );
		$GLOBALS['wgCirrusSearchRescoreProfile'] = 'global';
		try {
			$engine->activateTest( UserTestingStatus::active( 'some_test', 'a' ) );
			$this->assertSame( 'test', $GLOBALS['wgCirrusSearchRescoreProfile'] );
			$this->assertArrayNotHasKey( 'dontsetthisvariable', $GLOBALS,
				'must only set pre-existing global variables' );
		} finally {
			unset( $GLOBALS['wgCirrusSearchRescoreProfile'] );
		}
	}

	public function testPerBucketGlobalsOverridePerTestGlobals() {
		$config = self::CONFIG;
		$config['some_test']['globals'] = [ 'wgCirrusSearchRescoreProfile' => 'test' ];
		$config['some_test']['buckets']['a']['globals']['wgCirrusSearchRescoreProfile'] = 'bucket';

		$engine = new UserTestingEngine( $config, 'some_test', [ __CLASS__, 'one' ] );
		$status = UserTestingStatus::active( 'some_test', 'a' );
		$GLOBALS['wgCirrusSearchRescoreProfile'] = 'global';
		try {
			$engine->activateTest( $status );
			$this->assertSame( 'bucket', $GLOBALS['wgCirrusSearchRescoreProfile'] );
		} finally {
			unset( $GLOBALS['wgCirrusSearchRescoreProfile'] );
		}
	}

	public function testDoesntActivateInactiveStatus() {
		$config = self::CONFIG;
		$config['some_test']['globals'] = [ 'wgCirrusSearchRescoreProfile' => 'test' ];
		$engine = new UserTestingEngine( $config, 'some_test', [ __CLASS__, 'one' ] );
		$status = UserTestingStatus::inactive();
		$GLOBALS['wgCirrusSearchRescoreProfile'] = 'global';
		try {
			$engine->activateTest( $status );
			$this->assertSame( 'global', $GLOBALS['wgCirrusSearchRescoreProfile'],
				'configuration must be unchanged' );
		} finally {
			unset( $GLOBALS['wgCirrusSearchRescoreProfile'] );
		}
	}

	public function providerChooseBucket() {
		return [
			[ 'a', 0, [ 'a', 'b', 'c' ] ],
			[ 'a', 0, [ 'a', 'b', 'c', 'd' ] ],
			[ 'a', 0.24, [ 'a', 'b', 'c', 'd' ] ],
			[ 'b', 0.25, [ 'a', 'b', 'c', 'd' ] ],
			[ 'b', 0.26, [ 'a', 'b', 'c', 'd' ] ],
			[ 'b', 0.49, [ 'a', 'b', 'c', 'd' ] ],
			[ 'c', 0.50, [ 'a', 'b', 'c', 'd' ] ],
			[ 'c', 0.51, [ 'a', 'b', 'c', 'd' ] ],
			[ 'd', 1, [ 'a', 'b', 'c', 'd' ] ],
		];
	}

	/**
	 * @dataProvider providerChooseBucket
	 */
	public function testChooseBucket( $expect, $probability, array $buckets ) {
		$this->assertSame( $expect, UserTestingEngine::chooseBucket( $probability, $buckets ) );
	}

	public function testHexToProbability() {
		// Given the requirement of values between 0 and 1 the uniform distribution
		// must give a mean of 0.5 and a variance of 1/12. The md5 input meets the
		// uniform distribution requirement, this only checks that the function
		// doesn't lose that property.
		$sum = 0;
		$sqSumErr = 0;
		$n = 100;
		$expect = 0.5;
		for ( $i = 0; $i < $n; $i++ ) {
			$prob = UserTestingEngine::hexToProbability( md5( $i ) );
			$sum += $prob;
			$sqSumErr += pow( $prob - $expect, 2 );
		}
		$mean = $sum / $n;
		$var = $sqSumErr / $n;
		$this->assertEqualsWithDelta( $expect, $mean, 0.01,
			'mean of uniform distribution in [0,1] must be 0.5' );
		$this->assertEqualsWithDelta( 1 / 12, $var, 0.01,
			'variance of uniform distribution in [0,1] must be 1/12' );
	}

	public function testTrigger() {
		$this->assertSame( 'test:bucket', UserTestingStatus::active( 'test', 'bucket' )->getTrigger() );
	}

	public function testFromProvidedConfig() {
		$engine = UserTestingEngine::fromConfig( new HashSearchConfig( [
			'CirrusSearchUserTesting' => self::CONFIG,
			'CirrusSearchActiveTest' => 'some_test',
		] ) );
		// Mostly asserting it didn't blow up
		$status = $engine->decideTestByAutoenroll();
		$this->assertTrue( $status->isActive() );
		$this->assertSame( 'some_test', $status->getTestName() );
	}
}
