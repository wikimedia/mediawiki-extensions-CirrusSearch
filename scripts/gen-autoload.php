<?php

require_once __DIR__ . '/../../../includes/utils/AutoloadGenerator.php';

function main() {
	$base = dirname( __DIR__ );
	$generator = new AutoloadGenerator( $base );
	foreach ( array( 'includes', 'maintenance', 'profiles' ) as $dir ) {
		$generator->readDir( $base . '/' . $dir );
	}
	foreach ( glob( $base . '/*.php' ) as $file ) {
		$generator->readFile( $file );
	}
	$generator->readFile( dirname( __DIR__ ) . '/tests/unit/TestUtils.php' );

	$data = $generator->getAutoload( basename( __DIR__ ) . '/' . basename( __FILE__ ) );
	file_put_contents( $generator->getTargetFileinfo()['filename'], $data );

	echo "Done.\n\n";
}

main();
