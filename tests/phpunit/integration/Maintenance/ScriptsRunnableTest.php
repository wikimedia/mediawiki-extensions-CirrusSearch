<?php

namespace CirrusSearch\Maintenance;

use CirrusSearch\CirrusIntegrationTestCase;

/**
 * Asserts that maintenance scripts are loadable independently. These
 * classes are loaded prior to the autoloader and we need an assurance
 * they dont extend/implement something not available.
 * @coversNothing
 */
class ScriptsRunnableTest extends CirrusIntegrationTestCase {
	public function scriptPathProvider() {
		$it = new \DirectoryIterator( __DIR__ . '/../../../../maintenance/' );
		/** @var \SplFileInfo $fileInfo */
		foreach ( $it as $fileInfo ) {
			if ( $fileInfo->getExtension() === 'php' ) {
				yield $fileInfo->getFilename() => [ $fileInfo->getPathname() ];
			}
		}
	}

	/**
	 * @dataProvider scriptPathProvider
	 */
	public function testScriptCanBeLoaded( string $path ) {
		// phpcs:disable MediaWiki.Usage.ForbiddenFunctions
		$cmd = PHP_BINARY . ' ' .
			escapeshellarg( __DIR__ . '/ScriptsRunnablePreload.php' ) . ' ' .
			escapeshellarg( $path );
		exec( $cmd, $output, $retCode );
		// phpcs:enable

		// return code isn't useful, getting the help message returns 1
		// just like an error. Instead look for a message we know should
		// be in the help text.
		$this->assertSame( 0, $retCode,
			'Output (' . count( $output ) . ' lines): ' . implode( "\n", $output )
		);
	}
}
