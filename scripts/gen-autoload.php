<?php

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/includes/utils/AutoloadGenerator.php";

function main() {
	$base = dirname( __DIR__ );
	$generator = new AutoloadGenerator( $base );
	foreach ( [ 'includes', 'maintenance', 'profiles' ] as $dir ) {
		$generator->readDir( $base . '/' . $dir );
	}
	foreach ( glob( $base . '/*.php' ) as $file ) {
		$generator->readFile( $file );
	}
	$generator->readFile( dirname( __DIR__ ) . '/tests/unit/TestUtils.php' );
	$generator->readFile( dirname( __DIR__ ) . '/tests/unit/Query/BaseSimpleKeywordFeatureTest.php' );

	$data = $generator->getAutoload( basename( __DIR__ ) . '/' . basename( __FILE__ ) );
	file_put_contents( $generator->getTargetFileinfo()['filename'], $data );

	echo "Done.\n\n";
}

main();
