<?php

namespace CirrusSearch;

use MediaWikiTestCase;
use MediaWiki\MediaWikiServices;

/**
 * Base class for Cirrus test cases
 * @group CirrusSearch
 */
abstract class CirrusTestCase extends MediaWikiTestCase {
	const FIXTURE_DIR = __DIR__ . '/fixtures/';

	protected function setUp() {
		parent::setUp();
		MediaWikiServices::getInstance()
			->resetServiceForTesting( InterwikiResolver::SERVICE );
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
		$old = ini_set( 'serialize_precision', 14 );
		try {
			file_put_contents(
				self::FIXTURE_DIR . $testFile,
				json_encode( $fixture, JSON_PRETTY_PRINT )
			);
		} finally {
			ini_set( 'serialize_precision', $old );
		}
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
}
