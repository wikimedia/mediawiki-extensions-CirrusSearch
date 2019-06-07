<?php

namespace CirrusSearch;

use MediaWiki\Logger\LoggerFactory;
use MediaWikiTestCase;
use MediaWiki\MediaWikiServices;

/**
 * Base class for Cirrus test cases
 * @group CirrusSearch
 */
abstract class CirrusTestCase extends MediaWikiTestCase {
	const FIXTURE_DIR = __DIR__ . '/fixtures/';
	const CIRRUS_REBUILD_FIXTURES = 'CIRRUS_REBUILD_FIXTURES';

	/**
	 * @var int|null (lazy loaded)
	 */
	private static $SEED;

	/**
	 * @var int
	 */
	private static $MAX_TESTED_FIXTURES_PER_TEST;

	/**
	 * @return bool
	 */
	public static function canRebuildFixture() {
		return getenv( self::CIRRUS_REBUILD_FIXTURES ) === 'yes';
	}

	/**
	 * @return int
	 */
	public static function getSeed() {
		if ( self::$SEED === null ) {
			if ( is_numeric( getenv( 'CIRRUS_SEARCH_UNIT_TESTS_SEED' ) ) ) {
				self::$SEED = intval( getenv( 'CIRRUS_SEARCH_UNIT_TESTS_SEED' ) );
			} else {
				self::$SEED = time();
			}
		}
		return self::$SEED;
	}

	/**
	 * @return int
	 */
	public static function getMaxTestedFixturesPerTest() {
		if ( self::$MAX_TESTED_FIXTURES_PER_TEST === null ) {
			if ( is_numeric( getenv( 'CIRRUS_SEARCH_UNIT_TESTS_MAX_FIXTURES_PER_TEST' ) ) ) {
				self::$MAX_TESTED_FIXTURES_PER_TEST = intval( getenv( 'CIRRUS_SEARCH_UNIT_TESTS_MAX_FIXTURES_PER_TEST' ) );
			} else {
				self::$MAX_TESTED_FIXTURES_PER_TEST = 200;
			}
		}
		return self::$MAX_TESTED_FIXTURES_PER_TEST;
	}

	protected function setUp() {
		parent::setUp();
		$services = MediaWikiServices::getInstance();
		$services->resetServiceForTesting( InterwikiResolver::SERVICE );
		// MediaWikiTestCase::makeTestConfigFactoryInstantiator ends up carrying
		// over the same instance of SearchConfig between tests. Evil but necessary.
		$services->getConfigFactory()->makeConfig( 'CirrusSearch' )->clearCachesForTesting();
	}

	public static function setUpBeforeClass() {
		parent::setUpBeforeClass();
		LoggerFactory::getInstance( 'CirrusSearchUnitTest' )->debug( 'Using seed ' . self::getSeed() );
	}

	public static function findFixtures( $path ) {
		$prefixLen = strlen( self::FIXTURE_DIR );
		$results = [];
		foreach ( glob( self::FIXTURE_DIR . $path ) as $file ) {
			$results[] = substr( $file, $prefixLen );
		}
		return $results;
	}

	public static function saveFixture( $testFile, $fixture ) {
		file_put_contents(
			self::FIXTURE_DIR . $testFile,
			self::encodeFixture( $fixture )
		);
	}

	public static function encodeFixture( $fixture ) {
		$old = ini_set( 'serialize_precision', 14 );
		try {
			return json_encode( $fixture, JSON_PRETTY_PRINT );
		} finally {
			ini_set( 'serialize_precision', $old );
		}
	}

	/**
	 * @param array $cases
	 * @return array
	 */
	public static function randomizeFixtures( array $cases ): array {
		if ( self::canRebuildFixture() ) {
			return $cases;
		}
		ksort( $cases );
		srand( self::getSeed() );
		$randomizedKeys = array_rand( $cases, min( count( $cases ), self::getMaxTestedFixturesPerTest() ) );
		$randomizedCases = array_intersect_key( $cases, array_flip( $randomizedKeys ) );
		return $randomizedCases;
	}

	public static function hasFixture( $testFile ) {
		return is_file( self::FIXTURE_DIR . $testFile );
	}

	public static function loadFixture( $testFile, $errorMessage = "fixture config" ) {
		$decoded = json_decode( file_get_contents( self::FIXTURE_DIR . $testFile ), true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			throw new \RuntimeException( "Failed decoding {$errorMessage}: $testFile" );
		}
		return $decoded;
	}

	public static function fixturePath( $testFile ) {
		return self::FIXTURE_DIR . $testFile;
	}

	/**
	 * Capture the args of a mocked method
	 *
	 * @param mixed &$args placeholder for args to capture
	 * @param callable|null $callback optional callback methods to run on captured args
	 * @return \PHPUnit\Framework\Constraint\Callback
	 * @see Assert::callback()
	 */
	public function captureArgs( &$args, callable $callback = null ) {
		return $this->callback( function ( ...$argToCapture ) use ( &$args, $callback ) {
			$args = $argToCapture;
			if ( $callback !== null ) {
				return $callback( $argToCapture );
			}
			return true;
		} );
	}

	/**
	 * @param \Elastica\Response ...$responses
	 * @return \Elastica\Transport\AbstractTransport
	 */
	public function mockTransportWithResponse( ...$responses ) {
		$transport = $this->getMockBuilder( \Elastica\Transport\AbstractTransport::class )
			->disableOriginalConstructor()
			->getMock();
		$transport->expects( $this->any() )
			->method( 'exec' )
			->willReturnOnConsecutiveCalls( ...$responses );
		return $transport;
	}
}
