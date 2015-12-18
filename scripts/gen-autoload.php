<?php

require_once __DIR__ . '/../../../includes/utils/AutoloadGenerator.php';

function main() {
	$base = dirname( __DIR__ );
	$generator = new AutoloadGenerator( $base );
	foreach ( array( 'includes', 'maintenance' ) as $dir ) {
		$generator->readDir( $base . '/' . $dir );
	}
	foreach ( glob( $base . '/*.php' ) as $file ) {
		$generator->readFile( $file );
	}
	$generator->readFile( __DIR__ . 'tests/phpunit/TestUtils.php' );

	$generator->generateAutoload( basename( __DIR__ ) . '/' . basename( __FILE__ ) );

	echo "Done.\n\n";
}

main();
