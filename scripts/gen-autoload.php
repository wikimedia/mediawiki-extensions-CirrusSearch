<?php

require_once __DIR__ . '/../../../includes/utils/AutoloadGenerator.php';

function main() {
	$base = dirname( __DIR__ );
	$generator = new AutoloadGenerator( $base );
	foreach ( array( 'includes', 'maintenance', 'tests/unit' ) as $dir ) {
		$generator->readDir( $base . '/' . $dir );
	}
	foreach ( glob( $base . '/*.php' ) as $file ) {
		$generator->readFile( $file );
	}

	$generator->generateAutoload( basename( __DIR__ ) . '/' . basename( __FILE__ ) );

	echo "Done.\n\n";
}

main();
